<?php
/**
 * ==============================================================================
 * 🔍 CueRCode - Site Polish Check ("does it look AI-made?") (#137)
 * ==============================================================================
 *
 * Path: tools/check_site_polish.php
 *
 * A fast, deterministic check for the small details that make a website look
 * unfinished or machine-generated. It reads the source files only - no
 * database, no web server, no network - so it runs the same way on a laptop,
 * in a cloud session and in CI (.github/workflows/ci.yml runs it on every push).
 *
 * What it checks (each check prints file:line and a plain-English reason):
 *   1. 🌐 Preview / staging / localhost addresses left in the site
 *   2. 🏷️ Every page sets its own title and description (no duplicate titles)
 *   3. 🔗 The shared <head> prints canonical, Open Graph, Twitter and icon tags,
 *      and the icon / share-image files exist (share image 1200x630)
 *   4. 🔒 Every CDN integrity (SRI) hash matches the committed local copy of
 *      the same file - a wrong hash makes browsers refuse the file (this is
 *      exactly how Bootstrap's stylesheet was silently blocked before #137)
 *   5. 📦 Every local fallback file the pages point at exists
 *   6. 🐞 No console.log / console.debug / debugger left in first-party JS
 *   7. 🖼️ Every <img> has an alt attribute
 *   8. 🧭 Internal links: no ".php" addresses, no broken links, and folder
 *      links end in "/" (otherwise every click costs an extra redirect)
 *   9. 🗺️ sitemap.xml exists, robots.txt points at it, every listed address
 *      exists and every public page is listed
 *  10. 🚧 Custom 403/404/500 error pages are wired up in .htaccess
 *  11. 💬 No HTML comments shipped to visitors from PHP templates (developer
 *      notes belong in PHP comments, which never reach the browser)
 *  12. ✍️ Visible text: no lorem ipsum / TODO / FIXME, no stock "AI" phrases,
 *      no internal ticket or epic codes, no hard-coded copyright year;
 *      unfilled "[TEMPLATE: ...]" legal placeholders are reported as warnings
 *  13. 🇬🇧 Pages declare UK English (lang="en-GB"); no Internet Explorer tags
 *  14. 📱 manifest.json: UK English, no combined "any maskable" icons
 *  15. 📐 CSS grids use minmax(0, 1fr) so columns can never overflow and clip
 *  16. 🤖 robots.txt does not hide the public API documentation
 *  17. 🎨 No made-up Bootstrap classes (text-bg-*-subtle renders invisibly)
 *
 * Usage (from the repository root):
 *   php tools/check_site_polish.php
 * Exit code: 0 = no problems (warnings allowed), 1 = at least one problem.
 *
 * The human-eye half of the standing rule (screen sizes, long text, empty and
 * error states, visual consistency, every button really works) cannot be
 * scripted here - see "Polish check" in .claude/CLAUDE.md.
 *
 * @package     CueRCode
 * @subpackage  Build Tools
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Issue #137
 */

declare(strict_types=1);

// ==============================================================================
// 🚫 Command line only - this file must never answer a web request
// ==============================================================================
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// ==============================================================================
// 📁 Paths
// ==============================================================================
$ds         = DIRECTORY_SEPARATOR;
$repoRoot   = dirname(__DIR__);
$siteRoot   = $repoRoot . $ds . 'web' . $ds . 'CueRCode.net';
$publicRoot = $siteRoot . $ds . 'public_html';
$landingRoot = $siteRoot . $ds . 'public_html_landing';
$includesRoot = $siteRoot . $ds . '_includes';
$functionsRoot = $siteRoot . $ds . '_functions';

// 🗂️ Findings collected by every check: [severity, check, file, line, message]
$polishFindings = [];

// ==============================================================================
// 🧰 Helpers
// ==============================================================================

/**
 * ➕ Record one finding.
 *
 * @param array<int, array<string, mixed>> $findings  Collected findings (by reference)
 * @param string $severity  'error' (fails the check) or 'warning' (reported only)
 * @param string $check     Short name of the check
 * @param string $file      Absolute path of the file concerned
 * @param int    $line      1-based line number (0 = whole file)
 * @param string $message   Plain-English description of the problem
 */
function polishAdd(array &$findings, string $severity, string $check, string $file, int $line, string $message): void
{
    $findings[] = [
        'severity' => $severity,
        'check'    => $check,
        'file'     => $file,
        'line'     => $line,
        'message'  => $message,
    ];
}

/**
 * 📍 Path relative to the repository root, with forward slashes, for output.
 */
function polishRelative(string $path, string $repoRoot): string
{
    $relative = $path;
    if (str_starts_with($path, $repoRoot . DIRECTORY_SEPARATOR)) {
        $relative = substr($path, strlen($repoRoot) + 1);
    }
    return str_replace(DIRECTORY_SEPARATOR, '/', $relative);
}

/**
 * 🔢 1-based line number of a byte offset inside a string.
 */
function polishLineAt(string $content, int $offset): int
{
    return substr_count(substr($content, 0, $offset), "\n") + 1;
}

/**
 * 📂 List files under a folder with one of the given extensions, skipping
 * vendored third-party folders and the emoji image set.
 *
 * @param  string[] $extensions  Lower-case extensions without the dot
 * @return string[]              Absolute paths, sorted
 */
function polishListFiles(string $dir, array $extensions): array
{
    $files = [];
    if (!is_dir($dir)) {
        return $files;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $fileInfo) {
        $path = $fileInfo->getPathname();
        $normalised = str_replace(DIRECTORY_SEPARATOR, '/', $path);
        // 🚫 Third-party code and the emoji image set are not ours to polish
        if (str_contains($normalised, '/assets/vendor/') || str_contains($normalised, '/assets/img/emoji/')) {
            continue;
        }
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($extension, $extensions, true)) {
            $files[] = $path;
        }
    }
    sort($files);
    return $files;
}

/**
 * 🧩 The HTML a PHP template sends to the browser, with every PHP block
 * replaced by a marker (newlines kept, so line numbers still match).
 * Text that contains the marker came partly from PHP and is skipped by
 * checks that need a literal value (such as a link address).
 */
function polishHtmlOnly(string $source): string
{
    $marker = '§PHP§';
    $html   = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token) && $token[0] === T_INLINE_HTML) {
            $html .= $token[1];
        } else {
            if (is_array($token)) {
                $text = $token[1];
            } else {
                $text = $token;
            }
            $html .= $marker . str_repeat("\n", substr_count($text, "\n"));
        }
    }
    return $html;
}

/**
 * 👀 Text a visitor can see or that PHP prints: the inline HTML of a template
 * (without HTML comments) plus PHP string literals, each with its line number.
 * PHP comments are never included - developer notes there are fine.
 *
 * @return array<int, array{0: string, 1: int}>  [text, starting line]
 */
function polishVisibleSegments(string $source): array
{
    $segments = [];
    foreach (token_get_all($source) as $token) {
        if (!is_array($token)) {
            continue;
        }
        if ($token[0] === T_INLINE_HTML) {
            // 💬 Blank out HTML comments but keep their newlines
            $text = preg_replace_callback('/<!--.*?-->/s', function (array $match): string {
                return str_repeat("\n", substr_count($match[0], "\n"));
            }, $token[1]);
            $segments[] = [(string) $text, $token[2]];
        } elseif ($token[0] === T_CONSTANT_ENCAPSED_STRING || $token[0] === T_ENCAPSED_AND_WHITESPACE) {
            $segments[] = [$token[1], $token[2]];
        }
    }
    return $segments;
}

/**
 * 🧭 Work out what a site-root-relative address points at.
 *
 * @return string  'page' (a PHP page or static file), 'folder' (folder with
 *                 an index page), 'folder-noslash' (folder, but the link has
 *                 no trailing "/"), or 'missing'
 */
function polishResolveAddress(string $publicRoot, string $address): string
{
    $ds = DIRECTORY_SEPARATOR;
    if ($address === '/') {
        return 'folder';
    }
    $relative = str_replace('/', $ds, ltrim($address, '/'));
    $full = $publicRoot . $ds . $relative;
    if (str_ends_with($address, '/')) {
        if (is_file($full . 'index.php') || is_file($full . 'index.html')) {
            return 'folder';
        }
        return 'missing';
    }
    if (is_file($full)) {
        return 'page';
    }
    if (is_file($full . '.php')) {
        return 'page';
    }
    if (is_dir($full)) {
        if (is_file($full . $ds . 'index.php') || is_file($full . $ds . 'index.html')) {
            return 'folder-noslash';
        }
    }
    return 'missing';
}

// ==============================================================================
// 📋 The files to check
// ==============================================================================
$publicPhp   = polishListFiles($publicRoot, ['php']);
$templateFiles = $publicPhp;
foreach (['header.php', 'footer.php', 'developers_nav.php'] as $sharedTemplate) {
    $templateFiles[] = $includesRoot . $ds . $sharedTemplate;
}
$templateFiles[] = $landingRoot . $ds . 'index.php';
$templateFiles = array_values(array_filter($templateFiles, 'is_file'));

// 🚫 The installer is blocked from the web and removed after setup, and the
//    version-info file prints nothing; neither is a visitor-facing page.
$templateFiles = array_values(array_filter($templateFiles, function (string $path): bool {
    $normalised = str_replace(DIRECTORY_SEPARATOR, '/', $path);
    if (str_contains($normalised, '/public_html/install/')) {
        return false;
    }
    if (str_ends_with($normalised, '/infoAppVer.php')) {
        return false;
    }
    return true;
}));

$firstPartyJs = polishListFiles($publicRoot . $ds . 'assets' . $ds . 'js', ['js']);
if (is_file($publicRoot . $ds . 'sw.js')) {
    $firstPartyJs[] = $publicRoot . $ds . 'sw.js';
}

// ==============================================================================
// 1. 🌐 Preview / staging / localhost addresses
// ==============================================================================
$previewPattern = '~(dreamhosters\.com|vercel\.app|netlify\.app|\.pages\.dev|herokuapp\.com|\.github\.io|ngrok(-free)?\.(io|app)|//localhost\b|//127\.0\.0\.1)~i';
$addressFiles = array_merge(
    polishListFiles($publicRoot, ['php', 'js', 'css', 'html', 'json', 'xml', 'txt', 'htaccess']),
    polishListFiles($landingRoot, ['php', 'html']),
    polishListFiles($includesRoot, ['php']),
    polishListFiles($functionsRoot, ['php'])
);
foreach ($addressFiles as $file) {
    $content = (string) file_get_contents($file);
    if (str_ends_with($file, '.php')) {
        // 💬 Comments may mention such hosts (e.g. examples); code and output may not
        $scan = '';
        foreach (token_get_all($content) as $token) {
            if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
                $scan .= str_repeat("\n", substr_count($token[1], "\n"));
            } elseif (is_array($token)) {
                $scan .= $token[1];
            } else {
                $scan .= $token;
            }
        }
    } else {
        $scan = $content;
    }
    if (preg_match_all($previewPattern, $scan, $matches, PREG_OFFSET_CAPTURE) > 0) {
        foreach ($matches[0] as $match) {
            polishAdd($polishFindings, 'error', 'preview-address', $file, polishLineAt($scan, (int) $match[1]),
                'Preview/development address "' . $match[0] . '" - visitors must only ever see cuercode.net addresses.');
        }
    }
}

// ==============================================================================
// 2. 🏷️ Every page sets its own title and description
// ==============================================================================
$pagesWithHeader = [];
$titlesSeen = [];
foreach ($publicPhp as $file) {
    $content = (string) file_get_contents($file);
    if (!str_contains($content, "'header.php'")) {
        continue;
    }
    $pagesWithHeader[] = $file;
    if (preg_match('/^\$pageTitle\s*=\s*(.+?);\s*$/m', $content, $titleMatch, PREG_OFFSET_CAPTURE) !== 1) {
        polishAdd($polishFindings, 'error', 'page-title', $file, 0,
            'Page has no $pageTitle, so it falls back to the generic site title.');
    } else {
        $titleValue = trim($titleMatch[1][0]);
        if (isset($titlesSeen[$titleValue])) {
            polishAdd($polishFindings, 'error', 'page-title', $file, polishLineAt($content, (int) $titleMatch[0][1]),
                'Same page title as ' . $titlesSeen[$titleValue] . ' - every page needs its own.');
        } else {
            $titlesSeen[$titleValue] = polishRelative($file, $repoRoot);
        }
    }
    if (preg_match('/^\$pageDescription\s*=/m', $content) !== 1) {
        polishAdd($polishFindings, 'error', 'page-description', $file, 0,
            'Page has no $pageDescription, so search results and share previews show generic text.');
    }
}

// ==============================================================================
// 3. 🔗 Shared <head>: canonical, Open Graph, Twitter, icons
// ==============================================================================
$headerFile = $includesRoot . $ds . 'header.php';
$headerSource = (string) file_get_contents($headerFile);
$requiredHeadParts = [
    'name="description"'           => 'meta description',
    'rel="canonical"'              => 'canonical link',
    'property="og:title"'          => 'og:title',
    'property="og:description"'    => 'og:description',
    'property="og:url"'            => 'og:url',
    'property="og:image"'          => 'og:image',
    'property="og:image:width"'    => 'og:image:width',
    'property="og:site_name"'      => 'og:site_name',
    'name="twitter:card"'          => 'twitter:card',
    'rel="icon"'                   => 'favicon',
    'rel="apple-touch-icon"'       => 'apple-touch-icon',
    'rel="manifest"'               => 'web app manifest',
    'name="robots"'                => 'noindex support for private pages',
];
foreach ($requiredHeadParts as $needle => $label) {
    if (!str_contains($headerSource, $needle)) {
        polishAdd($polishFindings, 'error', 'head-tags', $headerFile, 0, 'The shared <head> is missing the ' . $label . '.');
    }
}
if (preg_match('/property="og:url"\s+content="<\?php echo htmlspecialchars\(\$canonicalURL/', $headerSource) !== 1) {
    polishAdd($polishFindings, 'error', 'head-tags', $headerFile, 0,
        'og:url must be the page\'s own canonical address, otherwise every share preview links to the homepage.');
}
if (preg_match_all('#"(/assets/img/branding/[^"]+)"#', $headerSource, $iconMatches, PREG_OFFSET_CAPTURE) > 0) {
    foreach ($iconMatches[1] as $iconMatch) {
        $iconPath = $publicRoot . str_replace('/', $ds, $iconMatch[0]);
        if (!is_file($iconPath)) {
            polishAdd($polishFindings, 'error', 'head-tags', $headerFile, polishLineAt($headerSource, (int) $iconMatch[1]),
                'Icon/image file ' . $iconMatch[0] . ' does not exist.');
        }
    }
}
$ogImage = $publicRoot . $ds . 'assets' . $ds . 'img' . $ds . 'branding' . $ds . 'og-image.png';
if (is_file($ogImage)) {
    $ogSize = getimagesize($ogImage);
    if ($ogSize === false || $ogSize[0] !== 1200 || $ogSize[1] !== 630) {
        polishAdd($polishFindings, 'error', 'head-tags', $ogImage, 0, 'The share image should be 1200x630 pixels.');
    }
} else {
    polishAdd($polishFindings, 'error', 'head-tags', $ogImage, 0, 'The share image (og-image.png) is missing.');
}

// ==============================================================================
// 4. 🔒 CDN integrity (SRI) hashes match the committed fallback copies
// ==============================================================================
// CDN address prefix => local folder holding a byte-identical copy
$cdnToLocal = [
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/'      => $publicRoot . $ds . 'assets' . $ds . 'vendor' . $ds . 'bootstrap' . $ds,
    'https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/'         => $publicRoot . $ds . 'assets' . $ds . 'vendor' . $ds . 'jquery' . $ds,
    'https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.33.0/'    => $publicRoot . $ds . 'assets' . $ds . 'vendor' . $ds . 'swagger-ui' . $ds,
    'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/' => $publicRoot . $ds . 'assets' . $ds . 'vendor' . $ds . 'fontawesome' . $ds,
];
$sriFiles = [
    $headerFile,
    $includesRoot . $ds . 'footer.php',
    $publicRoot . $ds . 'api' . $ds . 'docs' . $ds . 'index.php',
    $landingRoot . $ds . 'index.php',
];
foreach ($sriFiles as $file) {
    if (!is_file($file)) {
        continue;
    }
    $content = (string) file_get_contents($file);
    if (preg_match_all('/<(?:link|script)\b[^>]*>/s', $content, $tagMatches, PREG_OFFSET_CAPTURE) < 1) {
        continue;
    }
    foreach ($tagMatches[0] as $tagMatch) {
        $tag = $tagMatch[0];
        if (preg_match('/integrity="([^"]+)"/', $tag, $integrityMatch) !== 1) {
            continue;
        }
        if (preg_match('/(?:href|src)="(https:[^"]+)"/', $tag, $urlMatch) !== 1) {
            continue;
        }
        $line = polishLineAt($content, (int) $tagMatch[1]);
        $localFile = null;
        foreach ($cdnToLocal as $prefix => $localDir) {
            if (str_starts_with($urlMatch[1], $prefix)) {
                // 📁 The part after the prefix is the same path inside our copy
                $localFile = $localDir . str_replace('/', $ds, substr($urlMatch[1], strlen($prefix)));
            }
        }
        if ($localFile === null || !is_file($localFile)) {
            polishAdd($polishFindings, 'warning', 'sri-hash', $file, $line,
                'Cannot verify the integrity hash for ' . $urlMatch[1] . ' - no committed local copy to compare with.');
            continue;
        }
        $matched = false;
        foreach (preg_split('/\s+/', trim($integrityMatch[1])) as $integrityValue) {
            if (preg_match('/^(sha256|sha384|sha512)-(.+)$/', $integrityValue, $parts) !== 1) {
                continue;
            }
            $actual = base64_encode((string) hash_file($parts[1], $localFile, true));
            if (hash_equals($actual, $parts[2])) {
                $matched = true;
            }
        }
        if ($matched === false) {
            polishAdd($polishFindings, 'error', 'sri-hash', $file, $line,
                'The integrity hash for ' . $urlMatch[1] . ' does not match the file - browsers will refuse to load it.');
        }
    }
}

// ==============================================================================
// 5. 📦 Local fallback files exist
// ==============================================================================
foreach ($sriFiles as $file) {
    if (!is_file($file)) {
        continue;
    }
    $content = (string) file_get_contents($file);
    if (preg_match_all('#[\'"](/assets/vendor/[^\'"]+)[\'"]#', $content, $vendorMatches, PREG_OFFSET_CAPTURE) > 0) {
        foreach ($vendorMatches[1] as $vendorMatch) {
            $vendorPath = $publicRoot . str_replace('/', $ds, $vendorMatch[0]);
            if (!is_file($vendorPath)) {
                polishAdd($polishFindings, 'error', 'fallback-file', $file, polishLineAt($content, (int) $vendorMatch[1]),
                    'Fallback file ' . $vendorMatch[0] . ' is not in the repository, so the fallback loads nothing.');
            }
        }
    }
}

// ==============================================================================
// 6. 🐞 Debug output in first-party JavaScript
// ==============================================================================
foreach ($firstPartyJs as $file) {
    $content = (string) file_get_contents($file);
    if (preg_match_all('/\bconsole\.(log|debug|info|trace)\s*\(|\bdebugger\s*;/', $content, $debugMatches, PREG_OFFSET_CAPTURE) > 0) {
        foreach ($debugMatches[0] as $debugMatch) {
            polishAdd($polishFindings, 'error', 'debug-output', $file, polishLineAt($content, (int) $debugMatch[1]),
                'Leftover "' . trim($debugMatch[0]) . '" - remove it (console.warn/console.error are fine for real problems).');
        }
    }
    // 🎨 Made-up Bootstrap classes built in JavaScript render invisibly too
    if (preg_match_all('/\btext-bg-(?:primary|secondary|success|danger|warning|info|light|dark)-subtle\b/', $content, $jsClassMatches, PREG_OFFSET_CAPTURE) > 0) {
        foreach ($jsClassMatches[0] as $jsClassMatch) {
            polishAdd($polishFindings, 'error', 'css-class', $file, polishLineAt($content, (int) $jsClassMatch[1]),
                '"' . $jsClassMatch[0] . '" is not a Bootstrap 5.3 class - use bg-*-subtle with text-*-emphasis.');
        }
    }
}

// ==============================================================================
// 7-8. 🖼️ Image alt text and 🧭 internal links (templates)
// ==============================================================================
$staticHtml = polishListFiles($publicRoot, ['html']);
foreach (array_merge($templateFiles, $staticHtml) as $file) {
    $source = (string) file_get_contents($file);
    if (str_ends_with($file, '.php')) {
        $html = polishHtmlOnly($source);
    } else {
        $html = $source;
    }
    $isLanding = str_contains(str_replace(DIRECTORY_SEPARATOR, '/', $file), '/public_html_landing/');

    // 🖼️ <img> without alt
    if (preg_match_all('/<img\b[^>]*>/s', $html, $imgMatches, PREG_OFFSET_CAPTURE) > 0) {
        foreach ($imgMatches[0] as $imgMatch) {
            if (preg_match('/\balt\s*=/', $imgMatch[0]) !== 1) {
                polishAdd($polishFindings, 'error', 'img-alt', $file, polishLineAt($html, (int) $imgMatch[1]),
                    'Image without alt text (use alt="" for decorative images).');
            }
        }
    }

    // 🧭 Internal links
    if ($isLanding === false && preg_match_all('/\bhref="(\/[^"#?]*)/', $html, $linkMatches, PREG_OFFSET_CAPTURE) > 0) {
        foreach ($linkMatches[1] as $linkMatch) {
            $address = $linkMatch[0];
            $line = polishLineAt($html, (int) $linkMatch[1]);
            if (str_contains($address, '§PHP§') || str_starts_with($address, '//')) {
                continue;
            }
            if (str_starts_with($address, '/api/v1/') || str_starts_with($address, '/auth/')) {
                continue;
            }
            if (str_ends_with($address, '.php')) {
                polishAdd($polishFindings, 'error', 'links', $file, $line,
                    'Link to "' . $address . '" - web addresses on this site never end in .php.');
                continue;
            }
            $resolved = polishResolveAddress($publicRoot, $address);
            if ($resolved === 'missing') {
                polishAdd($polishFindings, 'error', 'links', $file, $line, 'Broken link: "' . $address . '" does not exist.');
            } elseif ($resolved === 'folder-noslash') {
                polishAdd($polishFindings, 'error', 'links', $file, $line,
                    'Link "' . $address . '" points at a folder without the trailing "/" (costs an extra redirect).');
            }
        }
    }
}

// ==============================================================================
// 9. 🗺️ sitemap.xml and robots.txt
// ==============================================================================
$sitemapFile = $publicRoot . $ds . 'sitemap.xml';
$robotsFile  = $publicRoot . $ds . 'robots.txt';
$sitemapPaths = [];
if (!is_file($sitemapFile)) {
    polishAdd($polishFindings, 'error', 'sitemap', $sitemapFile, 0, 'There is no sitemap.xml.');
} else {
    $sitemapXml = (string) file_get_contents($sitemapFile);
    if (preg_match_all('#<loc>https://cuercode\.net(/[^<]*)</loc>#', $sitemapXml, $locMatches, PREG_OFFSET_CAPTURE) < 1) {
        polishAdd($polishFindings, 'error', 'sitemap', $sitemapFile, 0, 'sitemap.xml lists no https://cuercode.net addresses.');
    } else {
        foreach ($locMatches[1] as $locMatch) {
            $sitemapPaths[$locMatch[0]] = true;
            $resolved = polishResolveAddress($publicRoot, $locMatch[0]);
            if ($resolved === 'missing' || $resolved === 'folder-noslash' || str_ends_with($locMatch[0], '.php')) {
                polishAdd($polishFindings, 'error', 'sitemap', $sitemapFile, polishLineAt($sitemapXml, (int) $locMatch[1]),
                    'Sitemap address "' . $locMatch[0] . '" is not a canonical page address.');
            }
        }
    }
}
if (!is_file($robotsFile) || preg_match('/^Sitemap:\s*https:\/\/cuercode\.net\/sitemap\.xml\s*$/mi', (string) file_get_contents($robotsFile)) !== 1) {
    polishAdd($polishFindings, 'error', 'sitemap', $robotsFile, 0, 'robots.txt does not point at https://cuercode.net/sitemap.xml.');
}
// 📄 Every indexable public page should be in the sitemap
$notInSitemapByDesign = ['/forgot-password/'];
foreach ($pagesWithHeader as $file) {
    $content = (string) file_get_contents($file);
    if (preg_match('/^\$pageNoIndex\s*=\s*true\s*;/m', $content) === 1) {
        continue;
    }
    $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file, strlen($publicRoot)));
    if ($relative === '/index.php') {
        $canonical = '/';
    } elseif (str_ends_with($relative, '/index.php')) {
        $canonical = substr($relative, 0, -strlen('index.php'));
    } else {
        $canonical = substr($relative, 0, -strlen('.php'));
    }
    if (in_array($canonical, $notInSitemapByDesign, true)) {
        continue;
    }
    if (!isset($sitemapPaths[$canonical])) {
        polishAdd($polishFindings, 'error', 'sitemap', $file, 0,
            'Public page "' . $canonical . '" is missing from sitemap.xml (or set $pageNoIndex = true if it is private).');
    }
}

// ==============================================================================
// 10. 🚧 Custom error pages wired up
// ==============================================================================
$htaccessFile = $publicRoot . $ds . '.htaccess';
$htaccess = (string) file_get_contents($htaccessFile);
foreach (['403', '404', '500'] as $statusCode) {
    if (preg_match('#^\s*ErrorDocument\s+' . $statusCode . '\s+(/\S+)#m', $htaccess, $errorDocMatch) !== 1) {
        polishAdd($polishFindings, 'error', 'error-pages', $htaccessFile, 0,
            'No custom ' . $statusCode . ' page (ErrorDocument ' . $statusCode . ') - visitors would see the server\'s bare default.');
    } elseif (!is_file($publicRoot . str_replace('/', $ds, $errorDocMatch[1]))) {
        polishAdd($polishFindings, 'error', 'error-pages', $htaccessFile, 0,
            'ErrorDocument ' . $statusCode . ' points at ' . $errorDocMatch[1] . ', which does not exist.');
    }
}

// ==============================================================================
// 11-13. 💬 HTML comments, ✍️ visible text, 🇬🇧 language
// ==============================================================================
$stockPhrases = [
    'unlock the power', 'unlock premium', 'seamless', 'elevate your', "in today's digital",
    'supercharge', 'game-changer', 'cutting-edge', 'revolutioni', 'for any purpose',
    'lorem ipsum',
];
foreach ($templateFiles as $file) {
    $source = (string) file_get_contents($file);
    $html = polishHtmlOnly($source);

    // 💬 HTML comments reach every visitor's "View source"
    if (preg_match_all('/<!--/', $html, $commentMatches, PREG_OFFSET_CAPTURE) > 0) {
        polishAdd($polishFindings, 'error', 'html-comments', $file, polishLineAt($html, (int) $commentMatches[0][0][1]),
            count($commentMatches[0]) . ' HTML comment(s) are sent to visitors - turn them into PHP comments (<?php /* ... */ ?>).');
    }

    // 🎨 Bootstrap classes that don't exist render as nothing (e.g. a badge with
    //    white text and no background - invisible on white, as on /pricing)
    if (preg_match_all('/\btext-bg-(?:primary|secondary|success|danger|warning|info|light|dark)-subtle\b/', $html, $classMatches, PREG_OFFSET_CAPTURE) > 0) {
        foreach ($classMatches[0] as $classMatch) {
            polishAdd($polishFindings, 'error', 'css-class', $file, polishLineAt($html, (int) $classMatch[1]),
                '"' . $classMatch[0] . '" is not a Bootstrap 5.3 class - use bg-*-subtle with text-*-emphasis.');
        }
    }

    // 🇬🇧 Language and old tags
    if (preg_match('/<html\b[^>]*\blang="en"/', $html, $langMatch, PREG_OFFSET_CAPTURE) === 1) {
        polishAdd($polishFindings, 'error', 'language', $file, polishLineAt($html, (int) $langMatch[0][1]),
            'Page language is "en" - the site is written in UK English, so use lang="en-GB".');
    }
    if (preg_match('/X-UA-Compatible/i', $html, $uaMatch, PREG_OFFSET_CAPTURE) === 1) {
        polishAdd($polishFindings, 'error', 'language', $file, polishLineAt($html, (int) $uaMatch[0][1]),
            'Outdated Internet Explorer "X-UA-Compatible" tag - remove it.');
    }

    // ✍️ Visible text
    foreach (polishVisibleSegments($source) as $segment) {
        $text = $segment[0];
        $lower = strtolower($text);
        foreach ($stockPhrases as $phrase) {
            $position = strpos($lower, $phrase);
            if ($position !== false) {
                polishAdd($polishFindings, 'error', 'wording', $file, $segment[1] + polishLineAt($text, $position) - 1,
                    'Stock phrase "' . $phrase . '" - say specifically what CueRCode does instead.');
            }
        }
        if (preg_match('/\b(TODO|FIXME|XXX)\b/', $text, $todoMatch, PREG_OFFSET_CAPTURE) === 1) {
            polishAdd($polishFindings, 'error', 'wording', $file, $segment[1] + polishLineAt($text, (int) $todoMatch[0][1]) - 1,
                'Leftover "' . $todoMatch[0][0] . '" in text that reaches visitors.');
        }
        if (preg_match('/\bEpic E[0-9]|\bG-0[0-9]{2}\b|github\.com\/MWBMPartners\/[^"\'\s]+\/issues/', $text, $internalMatch, PREG_OFFSET_CAPTURE) === 1) {
            polishAdd($polishFindings, 'error', 'wording', $file, $segment[1] + polishLineAt($text, (int) $internalMatch[0][1]) - 1,
                'Internal project reference "' . $internalMatch[0][0] . '" in public text.');
        }
        if (preg_match('/&copy;\s*20[0-9]{2}\b(?!\s*[–-])/', $text, $copyMatch, PREG_OFFSET_CAPTURE) === 1) {
            polishAdd($polishFindings, 'error', 'wording', $file, $segment[1] + polishLineAt($text, (int) $copyMatch[0][1]) - 1,
                'Hard-coded copyright year - print 2025–<current year> instead.');
        }
        if (preg_match_all('/\[TEMPLATE:[^\]]*\]/', $text, $templateMatches, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($templateMatches[0] as $templateMatch) {
                polishAdd($polishFindings, 'warning', 'wording', $file, $segment[1] + polishLineAt($text, (int) $templateMatch[1]) - 1,
                    'Unfilled legal placeholder shown to visitors: ' . $templateMatch[0] . ' (needs the owner\'s wording).');
            }
        }
    }
}

// ✍️ Public API description text (shown on /api/docs/ and /developers/reference/)
$openapiFile = $publicRoot . $ds . 'api' . $ds . 'v1' . $ds . 'openapi.json';
if (is_file($openapiFile)) {
    $openapiSource = (string) file_get_contents($openapiFile);
    if (preg_match_all('/"(?:description|summary|title)"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/', $openapiSource, $descMatches, PREG_OFFSET_CAPTURE) > 0) {
        foreach ($descMatches[1] as $descMatch) {
            if (preg_match('/\bEpic E[0-9]|\bG-0[0-9]{2}\b|⚠️ ?DARK|github\.com\/MWBMPartners\/[^"\s]+\/issues/u', $descMatch[0], $openapiInternal) === 1) {
                polishAdd($polishFindings, 'error', 'wording', $openapiFile, polishLineAt($openapiSource, (int) $descMatch[1]),
                    'Internal project note "' . $openapiInternal[0] . '" in the public API description.');
            }
        }
    }
}

// ==============================================================================
// 14. 📱 manifest.json
// ==============================================================================
$manifestFile = $publicRoot . $ds . 'manifest.json';
$manifest = json_decode((string) file_get_contents($manifestFile), true);
if (!is_array($manifest)) {
    polishAdd($polishFindings, 'error', 'manifest', $manifestFile, 0, 'manifest.json is not valid JSON.');
} else {
    if (!isset($manifest['lang']) || $manifest['lang'] !== 'en-GB') {
        polishAdd($polishFindings, 'error', 'manifest', $manifestFile, 0, 'manifest.json "lang" should be "en-GB".');
    }
    if (isset($manifest['icons']) && is_array($manifest['icons'])) {
        foreach ($manifest['icons'] as $icon) {
            if (isset($icon['purpose']) && str_contains((string) $icon['purpose'], 'any') && str_contains((string) $icon['purpose'], 'maskable')) {
                polishAdd($polishFindings, 'error', 'manifest', $manifestFile, 0,
                    'Icon ' . (string) $icon['src'] . ' is "any maskable" - Android crops maskable icons, so edge-to-edge logos get clipped.');
            }
            if (isset($icon['src']) && !is_file($publicRoot . str_replace('/', $ds, (string) $icon['src']))) {
                polishAdd($polishFindings, 'error', 'manifest', $manifestFile, 0, 'Manifest icon ' . (string) $icon['src'] . ' does not exist.');
            }
        }
    }
}

// ==============================================================================
// 15. 📐 CSS grid columns that can overflow and clip
// ==============================================================================
foreach (polishListFiles($publicRoot . $ds . 'assets' . $ds . 'css', ['css']) as $file) {
    $content = (string) file_get_contents($file);
    if (preg_match_all('/repeat\(\s*[0-9]+\s*,\s*1fr\s*\)/', $content, $gridMatches, PREG_OFFSET_CAPTURE) > 0) {
        foreach ($gridMatches[0] as $gridMatch) {
            polishAdd($polishFindings, 'error', 'css-grid', $file, polishLineAt($content, (int) $gridMatch[1]),
                '"' . $gridMatch[0] . '" lets long content push columns out of their box - use minmax(0, 1fr).');
        }
    }
}

// ==============================================================================
// 16. 🤖 robots.txt must not hide the public API docs
// ==============================================================================
if (is_file($robotsFile) && preg_match('/^Disallow:\s*\/api\/?\s*$/mi', (string) file_get_contents($robotsFile)) === 1) {
    polishAdd($polishFindings, 'error', 'robots', $robotsFile, 0,
        '"Disallow: /api/" also hides the public API documentation at /api/docs/ - disallow /api/v1/ instead.');
}

// ==============================================================================
// 📣 Report
// ==============================================================================
$errorCount = 0;
$warningCount = 0;
usort($polishFindings, function (array $a, array $b): int {
    $order = strcmp((string) $a['severity'], (string) $b['severity']);
    if ($order !== 0) {
        return $order;
    }
    $order = strcmp((string) $a['file'], (string) $b['file']);
    if ($order !== 0) {
        return $order;
    }
    return $a['line'] <=> $b['line'];
});
foreach ($polishFindings as $finding) {
    if ($finding['severity'] === 'error') {
        $errorCount++;
        $label = 'PROBLEM';
    } else {
        $warningCount++;
        $label = 'warning';
    }
    $location = polishRelative((string) $finding['file'], $repoRoot);
    if ($finding['line'] > 0) {
        $location .= ':' . $finding['line'];
    }
    // 🧹 One finding per line, even when the quoted text spans several lines
    $message = (string) preg_replace('/\s+/', ' ', (string) $finding['message']);
    echo '[' . $label . '] [' . $finding['check'] . '] ' . $location . ' - ' . $message . PHP_EOL;
}
echo PHP_EOL . 'Site polish check: ' . $errorCount . ' problem(s), ' . $warningCount . ' warning(s).' . PHP_EOL;
if ($errorCount > 0) {
    exit(1);
}
exit(0);
