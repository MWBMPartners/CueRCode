<?php
/**
 * ==============================================================================
 * 🧪 CueRCode - Predefined Centre-Logo PNG Twins, End to End (Issue #65 / B10)
 * ==============================================================================
 *
 * Path: tests/test_predefined_logo_png.php
 *
 * Proves that choosing a predefined brand logo as the QR centre icon works on
 * a host WITHOUT Imagick, because every logo SVG ships with a committed PNG
 * copy ("twin") made once by tools/render_logo_pngs.php.
 *
 *   Section 1 - every seeded logo file name has BOTH an SVG and a PNG on disk;
 *               the PNG has the PNG magic bytes, is 512x512 and has an alpha
 *               channel (transparent background).
 *   Section 2 - the REAL generation path, in a subprocess (so the fake
 *               database stub never leaks into other tests): for every logo id
 *               generateQRCode() returns a PNG that differs from the no-logo
 *               code, has the same size, keeps its corners (the QR's quiet
 *               zone) and changes its centre. An SVG-format code still
 *               embeds the logo as an <image>.
 *   Section 3 - the API `logo` parameter reaches the same customization key.
 *
 * ⚠️ NOT done here (no tool on this machine): decoding the codes with a real
 *    QR reader. zbarimg and OpenCV are not installed. Dimensions, the centre
 *    change and the untouched corners are asserted instead.
 *
 * @package     CueRCode
 * @subpackage  Tests
 * @copyright   2025–2026 MWBM Partners Ltd
 * @since       Issue #65 (B10)
 * @see         tools/render_logo_pngs.php
 * @see         web/CueRCode.net/_functions/fn_qr_logo.php - loadLogoImage()
 */

// ==============================================================================
// 🔧 Helper: run a PHP snippet in a fresh process with the test bootstrap loaded
// ==============================================================================
function runPredefinedLogoSnippet(string $snippet): string
{
    $bootstrapPath = CUERCODE_TEST_REPO_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'bootstrap.php';

    $program = '<?php' . PHP_EOL
        . 'error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);' . PHP_EOL
        . 'require ' . var_export($bootstrapPath, true) . ';' . PHP_EOL
        . $snippet . PHP_EOL;

    $tmpFile = tempnam(sys_get_temp_dir(), 'cuercode_test_logo_') . '.php';
    file_put_contents($tmpFile, $program);

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $stdout  = '';
    $process = proc_open([PHP_BINARY, $tmpFile], $descriptors, $pipes);

    if (is_resource($process)) {
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        // 📌 STDERR is drained and ignored; the snippet reports via STDOUT.
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        proc_close($process);
    }

    if (is_file($tmpFile)) {
        unlink($tmpFile);
    }

    return $stdout;
}

// ==============================================================================
// 📂 Section 1: files on disk
// ==============================================================================
$logosDir = CUERCODE_TEST_REPO_ROOT . 'web' . DIRECTORY_SEPARATOR . 'CueRCode.net'
    . DIRECTORY_SEPARATOR . 'public_html' . DIRECTORY_SEPARATOR . 'assets'
    . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . 'logos' . DIRECTORY_SEPARATOR;
$seedPath = CUERCODE_TEST_REPO_ROOT . 'web' . DIRECTORY_SEPARATOR . 'CueRCode.net'
    . DIRECTORY_SEPARATOR . '_includes' . DIRECTORY_SEPARATOR . 'sql'
    . DIRECTORY_SEPARATOR . 'seed_data.sql';

$seedSql = (string) file_get_contents($seedPath);
$seedBlockStart = strpos($seedSql, 'INSERT INTO `tblPredefinedLogos`');
$seedBlockEnd   = ($seedBlockStart === false) ? false : strpos($seedSql, ';', $seedBlockStart);
$seedBlock      = ($seedBlockStart === false || $seedBlockEnd === false)
    ? ''
    : substr($seedSql, $seedBlockStart, $seedBlockEnd - $seedBlockStart);

// 📌 Row N of the seed INSERT gets logoID N (auto-increment on a fresh install).
preg_match_all("/\('[a-z]+',\s*'[^']*',\s*'([a-z]+\.svg)'/", $seedBlock, $seedMatches);
$seedFileNames = $seedMatches[1];

assertEquals(26, count($seedFileNames), 'seed_data.sql lists 26 predefined logo files');

$pngMagic = "\x89PNG\r\n\x1a\n";

foreach ($seedFileNames as $seedFileName) {
    $svgPath = $logosDir . $seedFileName;
    $pngPath = $logosDir . pathinfo($seedFileName, PATHINFO_FILENAME) . '.png';

    assertTrue(is_file($svgPath), $seedFileName . ': SVG exists');
    assertTrue(is_file($pngPath), $seedFileName . ': committed PNG twin exists');

    if (is_file($pngPath)) {
        $pngBytes = (string) file_get_contents($pngPath);
        assertEquals($pngMagic, substr($pngBytes, 0, 8), $seedFileName . ': PNG twin has the PNG magic bytes');

        $info = getimagesize($pngPath);
        assertTrue(
            is_array($info) && $info[0] === 512 && $info[1] === 512,
            $seedFileName . ': PNG twin is 512x512'
        );
        // 📌 Colour type byte 6 (offset 25) = truecolour with alpha = transparent.
        assertEquals(6, ord($pngBytes[25]), $seedFileName . ': PNG twin has an alpha channel');
    }
}

// ==============================================================================
// 🌐 Section 2: the real generation path, one subprocess for all 26 ids
// ==============================================================================
$seedListExport = var_export($seedFileNames, true);

$report = runPredefinedLogoSnippet(<<<PHP
ini_set('display_errors', '0');
requireLibraryAutoloader();
requireFn('fn_qr_payloads.php');
requireFn('fn_qr_options.php');
requireFn('fn_qr_logo.php');
requireFn('fn_qr_generate.php');

\$files = {$seedListExport};

// Fake database: answers ONLY the predefined-logo lookup (id -> file name).
class FakeLogoResult { public function __construct(private ?array \$row) {} public function fetch_assoc(): ?array { return \$this->row; } }
class FakeLogoStmt { public int \$insert_id = 0; public int \$affected_rows = 0; public function __construct(private ?array \$row) {} public function get_result(): FakeLogoResult { return new FakeLogoResult(\$this->row); } public function close(): bool { return true; } }
function dbQuery(string \$sql, string \$types = '', array \$params = []) {
    global \$files;
    if (str_contains(\$sql, 'tblPredefinedLogos')) {
        \$name = \$files[((int) \$params[0]) - 1] ?? null;
        return new FakeLogoStmt(\$name === null ? null : ['logoFileName' => \$name]);
    }
    return new FakeLogoStmt(null);
}

\$base = ['format' => 'png', 'size' => 400, 'ecc' => 'H', 'save_file' => false];
\$plain = generateQRCode('url', ['url' => 'https://cuercode.net/logo-test'], \$base);
function rawPng(array \$r): string { \$d = (string) \$r['data']; \$p = strpos(\$d, 'base64,'); return \$p === false ? \$d : (string) base64_decode(substr(\$d, \$p + 7)); }
\$plainImg = imagecreatefromstring(rawPng(\$plain));
\$w = imagesx(\$plainImg);
\$out = [];

foreach (\$files as \$index => \$file) {
    \$id = \$index + 1;
    \$r = generateQRCode('url', ['url' => 'https://cuercode.net/logo-test'], \$base + ['logo' => \$id]);
    \$ok = \$r['success'] && \$r['data'] !== \$plain['data'];
    \$img = \$ok ? imagecreatefromstring(rawPng(\$r)) : false;
    \$sameSize = \$img !== false && imagesx(\$img) === \$w && imagesy(\$img) === imagesy(\$plainImg);
    \$corner = \$sameSize && imagecolorat(\$img, 2, 2) === imagecolorat(\$plainImg, 2, 2);
    // Centre: compare an 80x80 block around the middle, counting differing pixels.
    \$diff = 0;
    if (\$sameSize) {
        \$c = intdiv(\$w, 2);
        for (\$x = \$c - 40; \$x < \$c + 40; \$x++) { for (\$y = \$c - 40; \$y < \$c + 40; \$y++) { if (imagecolorat(\$img, \$x, \$y) !== imagecolorat(\$plainImg, \$x, \$y)) { \$diff++; } } }
    }
    \$svg = generateQRCode('url', ['url' => 'https://cuercode.net/logo-test'], ['format' => 'svg', 'size' => 400, 'ecc' => 'H', 'save_file' => false, 'logo' => \$id]);
    \$svgOk = \$svg['success'] && str_contains(\$svg['data'], 'data:image/svg+xml;base64,');
    \$out[\$file] = [\$ok, \$sameSize, \$corner, \$diff, \$svgOk];
}
echo json_encode(\$out);
PHP
);

$perLogo = json_decode(trim($report), true);

assertTrue(is_array($perLogo) && count($perLogo) === 26, 'subprocess generated codes for all 26 logo ids (got: ' . substr($report, 0, 120) . ')');

if (is_array($perLogo)) {
    foreach ($perLogo as $fileName => $res) {
        assertTrue($res[0] === true, $fileName . ': logo id generates a PNG that differs from the no-logo code');
        assertTrue($res[1] === true, $fileName . ': logo code has the same dimensions as the no-logo code');
        assertTrue($res[2] === true, $fileName . ': quiet-zone corner pixel untouched by the logo');
        assertTrue($res[3] > 100, $fileName . ': centre of the code changed (' . $res[3] . ' pixels differ)');
        assertTrue($res[4] === true, $fileName . ': SVG-format code still embeds the SVG logo');
    }
}

// ==============================================================================
// 🔌 Section 3: API `logo` parameter reaches the same customization key
// ==============================================================================
$apiSource = (string) file_get_contents(
    CUERCODE_TEST_REPO_ROOT . 'web' . DIRECTORY_SEPARATOR . 'CueRCode.net'
    . DIRECTORY_SEPARATOR . '_functions' . DIRECTORY_SEPARATOR . 'fn_api_generate.php'
);
assertMatches(
    "/isset\(\\\$raw\['logo'\]\).*?\\\$customization\['logo'\]\s*=\s*\\\$raw\['logo'\]/s",
    $apiSource,
    'API generate: the logo parameter is copied into customization[logo], the key generateQRCode() resolves via getLogoPath()'
);

// 🔒 The tool must name its rasteriser fallback so the PNGs stay reproducible.
$toolSource = (string) file_get_contents(CUERCODE_TEST_REPO_ROOT . 'tools' . DIRECTORY_SEPARATOR . 'render_logo_pngs.php');
assertContains('rsvg-convert', $toolSource, 'tools/render_logo_pngs.php supports rsvg-convert');
