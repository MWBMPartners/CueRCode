<?php
/**
 * ==============================================================================
 * 🖼️ CueRCode - Predefined Logo PNG-Twin Generator (Issue #65)
 * ==============================================================================
 *
 * Path: tools/render_logo_pngs.php
 *
 * A small, CLI-only, build-time helper tool. It rasterises every predefined
 * logo SVG in `public_html/assets/img/logos/` into a same-basename PNG
 * "twin" (e.g. `facebook.svg` -> `facebook.png`) living in the SAME
 * directory, using the PHP `imagick` extension.
 *
 * WHY THIS TOOL EXISTS (Issue #65):
 * `_functions/fn_qr_logo.php`'s `loadLogoImage()` loads logo files into a GD
 * image resource for RASTER (PNG/JPG/GIF/WEBP) QR code output. GD has NO
 * vector renderer and cannot rasterise SVG under any circumstances - it can
 * only decode raster formats. All 26 predefined logos ship as SVG, so raster
 * QR output previously dropped predefined logos silently.
 *
 * `loadLogoImage()` now looks for a PNG "twin" file next to any `.svg` logo
 * FIRST (before falling back to the optional `imagick` PHP extension, and
 * before giving up with a logged warning). This tool is how that twin gets
 * generated: run it ONCE on any machine that has a working SVG rasteriser
 * (this sandboxed development/test environment deliberately does NOT - there
 * is no `imagick`, `rsvg-convert`, `Inkscape`, or `ImageMagick` CLI binary
 * installed here), commit the generated PNGs, and raster logo overlay then
 * "just works" on EVERY deployment with zero runtime SVG-rasterisation cost
 * or dependency.
 *
 * USAGE (from any environment WITH a rasteriser - a developer workstation or
 * a CI job, never this sandboxed environment):
 *
 *     php tools/render_logo_pngs.php
 *
 * Optional flags:
 *
 *     --force     Re-render every PNG twin even if one already exists
 *                 (useful after an SVG source file has been edited).
 *     --dir=PATH  Override the logos directory (defaults to the real
 *                 public_html/assets/img/logos/ directory, resolved
 *                 relative to this script - useful for testing the tool
 *                 itself against a scratch directory).
 *
 * IDEMPOTENCY: by default, an SVG that already has an up-to-date PNG twin
 * (twin exists AND is newer than or the same age as the source SVG) is
 * SKIPPED, not re-rendered - safe to run repeatedly / in CI on every commit
 * without needless churn. Pass --force to always re-render.
 *
 * REQUIRES A RASTERISER: this script deliberately does NOT attempt any
 * fallback rendering of its own (no hand-rolled SVG parser) - if the
 * `imagick` PHP extension is not loaded, it prints a clear explanation and
 * exits non-zero (1) rather than silently doing nothing or half-finishing.
 * It must NEVER be run inside this repository's sandboxed dev/test
 * environment (no rasteriser is installed there by design) - it is safe to
 * invoke from a rasteriser-equipped environment only (a developer's own
 * machine, or a CI job with `imagick` installed).
 *
 * @package     CueRCode
 * @subpackage  Build Tools
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Issue #65 (predefined centre-logos are dead at runtime)
 * @see         https://www.php.net/manual/en/book.imagick.php
 * @see         web/CueRCode.net/_functions/fn_qr_logo.php - loadLogoImage()'s
 *              PNG-twin lookup (the runtime consumer of this tool's output)
 * @see         https://www.php.net/manual/en/features.commandline.php
 */

// ==============================================================================
// 🚫 Step 0: CLI-only guard
// ==============================================================================
// This is a build-time tool, never a web-reachable script. Refuse to run
// under a web SAPI so it can never be invoked over HTTP even if it were ever
// accidentally deployed alongside the production site.
// @see https://www.php.net/manual/en/function.php-sapi-name.php
// ==============================================================================

if (PHP_SAPI !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    exit('This tool is CLI-only.');
}

// ==============================================================================
// 🧩 Step 1: Parse CLI arguments (--force, --dir=PATH)
// ==============================================================================
// @see https://www.php.net/manual/en/reserved.variables.argv.php
// ==============================================================================

$forceRerender  = false;
$overrideLogosDir = null;

foreach ($argv as $argumentIndex => $argumentValue) {
    // 📌 Skip $argv[0] - that is always the script's own path, not a flag.
    if ($argumentIndex === 0) {
        continue;
    }

    if ($argumentValue === '--force') {
        $forceRerender = true;
        continue;
    }

    if (str_starts_with($argumentValue, '--dir=')) {
        $overrideLogosDir = substr($argumentValue, strlen('--dir='));
        continue;
    }

    // ❓ An unrecognised flag - fail loudly rather than silently ignoring a
    //    typo (e.g. a caller who meant --force but typed --forse).
    fwrite(STDERR, 'ERROR: Unrecognised argument: ' . $argumentValue . PHP_EOL);
    fwrite(STDERR, 'Usage: php tools/render_logo_pngs.php [--force] [--dir=PATH]' . PHP_EOL);
    exit(1);
}

// ==============================================================================
// 📂 Step 2: Resolve the logos directory
// ==============================================================================
// Anchored relative to THIS script's own location so the tool works from any
// current working directory it is invoked from, exactly like tests/run.php's
// own path-resolution convention.
// ==============================================================================

if ($overrideLogosDir !== null && $overrideLogosDir !== '') {
    $logosDirectory = $overrideLogosDir;
} else {
    // 📌 tools/render_logo_pngs.php -> <repo root>/web/CueRCode.net/public_html/assets/img/logos
    $repoRoot       = dirname(__DIR__);
    $logosDirectory = $repoRoot
        . DIRECTORY_SEPARATOR . 'web'
        . DIRECTORY_SEPARATOR . 'CueRCode.net'
        . DIRECTORY_SEPARATOR . 'public_html'
        . DIRECTORY_SEPARATOR . 'assets'
        . DIRECTORY_SEPARATOR . 'img'
        . DIRECTORY_SEPARATOR . 'logos';
}

if (!is_dir($logosDirectory)) {
    fwrite(STDERR, 'ERROR: Logos directory not found: ' . $logosDirectory . PHP_EOL);
    exit(1);
}

// ==============================================================================
// 🔎 Step 3: Verify a rasteriser is available (fail loudly, exit non-zero)
// ==============================================================================
// Deliberately no fallback rendering logic here - this is a one-off,
// developer/CI-run build tool, not a runtime code path, so there is no need
// to degrade gracefully the way loadLogoImage() does at request time.
// @see https://www.php.net/manual/en/function.extension-loaded.php
// ==============================================================================

// 🔧 Two rasterisers are supported, tried in this order:
//     1. the PHP `imagick` extension, if loaded;
//     2. the `rsvg-convert` command-line tool (librsvg), if on the PATH.
//    (#65: this is how the committed PNG twins were made - rsvg-convert 2.63.4.)
//    Nothing is ever faked: with neither available the tool stops, non-zero.
$useImagick      = extension_loaded('imagick');
$rsvgConvertPath = '';

if (!$useImagick) {
    // @see https://www.php.net/manual/en/function.shell-exec.php
    $whichOutput = trim((string) shell_exec('command -v rsvg-convert 2>/dev/null'));
    if ($whichOutput !== '') {
        $rsvgConvertPath = $whichOutput;
    }
}

if (!$useImagick && $rsvgConvertPath === '') {
    fwrite(STDERR, 'ERROR: No SVG rasteriser found (no PHP "imagick" extension, no rsvg-convert).' . PHP_EOL);
    fwrite(STDERR, PHP_EOL);
    fwrite(STDERR, 'Install librsvg (macOS: brew install librsvg; Debian: apt install librsvg2-bin)' . PHP_EOL);
    fwrite(STDERR, 'or enable php-imagick, then re-run:' . PHP_EOL);
    fwrite(STDERR, '    php tools/render_logo_pngs.php' . PHP_EOL);
    exit(1);
}

// ==============================================================================
// 🔎 Step 4: Discover every predefined logo SVG
// ==============================================================================
// @see https://www.php.net/manual/en/function.glob.php
// ==============================================================================

$svgPattern = $logosDirectory . DIRECTORY_SEPARATOR . '*.svg';
$svgFiles   = glob($svgPattern);

if ($svgFiles === false) {
    $svgFiles = [];
}

sort($svgFiles);

if (count($svgFiles) === 0) {
    fwrite(STDERR, 'ERROR: No .svg files found in: ' . $logosDirectory . PHP_EOL);
    exit(1);
}

echo 'CueRCode predefined logo PNG-twin generator' . PHP_EOL;
echo str_repeat('=', 60) . PHP_EOL;
echo 'Logos directory: ' . $logosDirectory . PHP_EOL;
echo 'Discovered ' . count($svgFiles) . ' SVG file(s).' . PHP_EOL;
if ($forceRerender) {
    echo 'Mode: --force (re-rendering every PNG twin regardless of freshness)' . PHP_EOL;
}
echo PHP_EOL;

// ==============================================================================
// 🖼️ Step 5: Rasterise each SVG to its PNG twin
// ==============================================================================

$renderedCount = 0;
$skippedCount  = 0;
$failedCount   = 0;

foreach ($svgFiles as $svgPath) {
    // 📌 The PNG twin lives in the SAME directory with the SAME basename,
    //    mirroring exactly what loadLogoImage() (fn_qr_logo.php) looks for.
    $pngTwinPath = pathinfo($svgPath, PATHINFO_DIRNAME)
        . DIRECTORY_SEPARATOR
        . pathinfo($svgPath, PATHINFO_FILENAME)
        . '.png';

    $svgBasename = basename($svgPath);
    $pngBasename = basename($pngTwinPath);

    // ==================================================================
    // ⏭️ Idempotency check: skip a twin that is already up to date, unless
    //    --force was passed.
    // @see https://www.php.net/manual/en/function.filemtime.php
    // ==================================================================
    if (!$forceRerender && file_exists($pngTwinPath)) {
        $svgModifiedTime = filemtime($svgPath);
        $pngModifiedTime = filemtime($pngTwinPath);

        if ($svgModifiedTime !== false
            && $pngModifiedTime !== false
            && $pngModifiedTime >= $svgModifiedTime
        ) {
            echo '  [SKIP]     ' . $svgBasename . ' -> ' . $pngBasename . ' (already up to date)' . PHP_EOL;
            $skippedCount++;
            continue;
        }
    }

    // ==================================================================
    // 🖌️ Rasterise via rsvg-convert, or Imagick (librsvg-backed) if loaded.
    // @see https://www.php.net/manual/en/imagick.readimage.php
    // @see https://www.php.net/manual/en/imagick.setimageformat.php
    // ==================================================================
    try {
        if ($rsvgConvertPath !== '') {
            // 🖌️ rsvg-convert: a square 512x512 transparent PNG. All 26 logos
            //    use a square 0 0 64 64 viewBox, so the logo keeps exactly the
            //    same margins as the SVG (no trimming, no distortion).
            //    escapeshellarg() quotes every path (no shell injection).
            $rsvgCommand = escapeshellarg($rsvgConvertPath)
                . ' --format=png --width=512 --height=512 --output='
                . escapeshellarg($pngTwinPath) . ' ' . escapeshellarg($svgPath) . ' 2>&1';
            $rsvgOutput = [];
            $rsvgStatus = 0;
            exec($rsvgCommand, $rsvgOutput, $rsvgStatus);

            if ($rsvgStatus !== 0) {
                throw new \RuntimeException('rsvg-convert failed: ' . implode(' ', $rsvgOutput));
            }
        } else {
            $imagickInstance = new \Imagick();

            // 📌 A higher resolution than the default gives a crisper source
            //    bitmap for GD's own subsequent imagecopyresampled() downscale
            //    inside overlayLogoOnRaster() - the logo is always resized down
            //    to a percentage of the QR code's width at overlay time, so a
            //    generously-sized source PNG avoids visible softness.
            // @see https://www.php.net/manual/en/imagick.setresolution.php
            $imagickInstance->setResolution(300, 300);
            $imagickInstance->setBackgroundColor(new \ImagickPixel('transparent'));

            $imagickInstance->readImage($svgPath);
            $imagickInstance->setImageFormat('png32');

            // 📌 Trim any incidental surrounding transparent margin so the
            //    logo fills its bounding box consistently, matching how a
            //    typical square icon SVG is expected to overlay.
            $imagickInstance->trimImage(0.0);
            $imagickInstance->setImagePage(0, 0, 0, 0);

            $writeResult = $imagickInstance->writeImage($pngTwinPath);

            $imagickInstance->clear();
            $imagickInstance->destroy();

            if ($writeResult !== true) {
                throw new \RuntimeException('Imagick::writeImage() returned false.');
            }

        }

        // 📌 Sanity-check the written file is actually a decodable PNG
        //    before declaring success.
        // @see https://www.php.net/manual/en/function.getimagesize.php
        $writtenDimensions = @getimagesize($pngTwinPath);

        if ($writtenDimensions === false) {
            throw new \RuntimeException('Written PNG failed getimagesize() validation.');
        }

        echo '  [RENDERED] ' . $svgBasename . ' -> ' . $pngBasename
            . ' (' . $writtenDimensions[0] . 'x' . $writtenDimensions[1] . 'px)' . PHP_EOL;
        $renderedCount++;
    } catch (\Throwable $renderException) {
        fwrite(
            STDERR,
            '  [FAILED]   ' . $svgBasename . ' - ' . $renderException->getMessage() . PHP_EOL
        );
        $failedCount++;
    }
}

// ==============================================================================
// 🧮 Step 6: Summary + exit code
// ==============================================================================

echo PHP_EOL;
echo str_repeat('=', 60) . PHP_EOL;
echo sprintf(
    'Done: %d rendered, %d skipped (already up to date), %d failed.',
    $renderedCount,
    $skippedCount,
    $failedCount
) . PHP_EOL;

if ($failedCount > 0) {
    // 🚦 Non-zero exit so a CI job that runs this tool fails visibly.
    exit(1);
}

exit(0);
