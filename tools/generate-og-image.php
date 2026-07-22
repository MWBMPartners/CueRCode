<?php

/**
 * 🎨 CueRCode Open Graph image generator (FG-024 / issue #43 / backlog B-037).
 *
 * Composes a crisp 1200x630 social-sharing card from the existing CueRCode
 * brand assets using PHP GD, and writes it to:
 *
 *     public_html/assets/img/branding/og-image.png
 *
 * The card design:
 *   - A brand-teal background with a subtle diagonal gradient
 *     (--cuercode-primary #2c7a7b → --cuercode-primary-dark #1a4a4a).
 *   - A rounded white "card" panel inset for contrast/legibility.
 *   - The CueRCode logo mark (transparent PNG) on the left.
 *   - The "CueRCode" wordmark + "Advanced QR Code Generator" tagline on the
 *     right, rendered with a TrueType font for crisp anti-aliased text.
 *   - A thin brand accent rule under the wordmark in the logo cyan (#06b6d4).
 *
 * 🛠️ Run locally (one-off — the committed PNG is the deliverable):
 *
 *     php tools/generate-og-image.php
 *
 * Requires PHP GD with FreeType + PNG support (verified on PHP 8.4 locally;
 * Dreamhost GD also bundles these). This script is a build-time tool only — it
 * is never invoked at request time.
 *
 * House style: full multi-line PHP, 4-space indent, always braces, lowercase
 * null/true/false, ||/&& not OR/AND, detailed comments.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// 📐 Canvas + path constants
// ---------------------------------------------------------------------------

// Open Graph recommends a 1.91:1 image at 1200x630 (matches Twitter/X summary
// large image too), so we target exactly that.
const OG_WIDTH  = 1200;
const OG_HEIGHT = 630;

// Resolve project paths relative to this script so it runs from any cwd.
$scriptDir   = __DIR__;
$projectRoot = dirname($scriptDir);
$brandingDir = $projectRoot
    . '/web/CueRCode.net/public_html/assets/img/branding';

$logoMarkPath = $brandingDir . '/logo-mark.png';
$outputPath   = $brandingDir . '/og-image.png';

// ---------------------------------------------------------------------------
// 🔤 Font selection — prefer a bundled-style sans; fall back across common
//     macOS / Linux TrueType faces so the generator is portable.
// ---------------------------------------------------------------------------

/**
 * Returns the first readable TrueType font path from a candidate list, or null.
 *
 * @param array<int, string> $candidates Absolute font paths to try in order.
 */
function firstReadableFont(array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (is_string($candidate) && $candidate !== '' && is_readable($candidate)) {
            return $candidate;
        }
    }

    return null;
}

// Bold face for the wordmark.
$boldFont = firstReadableFont([
    '/System/Library/Fonts/Supplemental/Arial Bold.ttf',          // macOS
    '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',       // Debian/Ubuntu
    '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
    '/Library/Fonts/Arial Bold.ttf',
]);

// Regular face for the tagline.
$regularFont = firstReadableFont([
    '/System/Library/Fonts/Supplemental/Arial.ttf',              // macOS
    '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',           // Debian/Ubuntu
    '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
    '/Library/Fonts/Arial.ttf',
]);

if ($boldFont === null || $regularFont === null) {
    fwrite(STDERR, "ERROR: Could not locate usable TrueType fonts on this system.\n");
    exit(1);
}

if (! is_readable($logoMarkPath)) {
    fwrite(STDERR, "ERROR: Logo mark not found at {$logoMarkPath}\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// 🖼️ Build the canvas
// ---------------------------------------------------------------------------

$canvas = imagecreatetruecolor(OG_WIDTH, OG_HEIGHT);

if ($canvas === false) {
    fwrite(STDERR, "ERROR: Failed to allocate canvas.\n");
    exit(1);
}

// We want a flat opaque PNG (no alpha) for the final card — social scrapers
// prefer a fully painted background.
imagealphablending($canvas, true);
imagesavealpha($canvas, false);

/**
 * Allocates an RGB colour from a #rrggbb hex string on the given image.
 *
 * @param resource|\GdImage $image The target image.
 * @param string            $hex   A #rrggbb colour (with or without leading #).
 * @return int The allocated colour identifier.
 */
function allocateHex($image, string $hex): int
{
    $hex = ltrim($hex, '#');
    $r   = (int) hexdec(substr($hex, 0, 2));
    $g   = (int) hexdec(substr($hex, 2, 2));
    $b   = (int) hexdec(substr($hex, 4, 2));

    $colour = imagecolorallocate($image, $r, $g, $b);

    // imagecolorallocate can return false; treat that as fatal during build.
    if ($colour === false) {
        fwrite(STDERR, "ERROR: Failed to allocate colour #{$hex}.\n");
        exit(1);
    }

    return $colour;
}

// 🎨 Brand palette (sourced from cuercode.css custom properties + logo SVG).
$primary     = [0x2c, 0x7a, 0x7b]; // --cuercode-primary
$primaryDark = [0x1a, 0x4a, 0x4a]; // --cuercode-primary-dark
$navy        = '#0f172a';          // logo mark dark
$cyan        = '#06b6d4';          // logo accent
$slate       = '#334155';          // tagline ink (logo SVG)

// ---------------------------------------------------------------------------
// 🌈 Paint a smooth top-left → bottom-right diagonal gradient between the
//     primary and primary-dark teals. We blend per-row using a normalised
//     factor so the card has depth without looking busy.
// ---------------------------------------------------------------------------

for ($y = 0; $y < OG_HEIGHT; $y++) {
    // Vertical position 0..1, eased slightly toward the darker tone lower down.
    $t = $y / (OG_HEIGHT - 1);

    $r = (int) round($primary[0] + ($primaryDark[0] - $primary[0]) * $t);
    $g = (int) round($primary[1] + ($primaryDark[1] - $primary[1]) * $t);
    $b = (int) round($primary[2] + ($primaryDark[2] - $primary[2]) * $t);

    $rowColour = imagecolorallocate($canvas, $r, $g, $b);

    if ($rowColour === false) {
        fwrite(STDERR, "ERROR: Failed to allocate gradient row colour.\n");
        exit(1);
    }

    imageline($canvas, 0, $y, OG_WIDTH - 1, $y, $rowColour);
}

// ---------------------------------------------------------------------------
// ▢ Draw a rounded white card panel inset from the edges. GD has no native
//    rounded rect, so we compose one from a filled rect plus arcs/discs at
//    each corner.
// ---------------------------------------------------------------------------

/**
 * Fills a rounded rectangle on the image.
 *
 * @param resource|\GdImage $image  Target image.
 * @param int               $x1     Left edge.
 * @param int               $y1     Top edge.
 * @param int               $x2     Right edge.
 * @param int               $y2     Bottom edge.
 * @param int               $radius Corner radius in pixels.
 * @param int               $colour Allocated fill colour.
 */
function fillRoundedRect($image, int $x1, int $y1, int $x2, int $y2, int $radius, int $colour): void
{
    // Centre cross.
    imagefilledrectangle($image, $x1 + $radius, $y1, $x2 - $radius, $y2, $colour);
    imagefilledrectangle($image, $x1, $y1 + $radius, $x2, $y2 - $radius, $colour);

    $diameter = $radius * 2;

    // Four corner discs (imagefilledarc paints relative to a centre point).
    imagefilledarc($image, $x1 + $radius, $y1 + $radius, $diameter, $diameter, 180, 270, $colour, IMG_ARC_PIE);
    imagefilledarc($image, $x2 - $radius, $y1 + $radius, $diameter, $diameter, 270, 360, $colour, IMG_ARC_PIE);
    imagefilledarc($image, $x1 + $radius, $y2 - $radius, $diameter, $diameter, 90, 180, $colour, IMG_ARC_PIE);
    imagefilledarc($image, $x2 - $radius, $y2 - $radius, $diameter, $diameter, 0, 90, $colour, IMG_ARC_PIE);
}

$white      = allocateHex($canvas, '#ffffff');
$margin     = 48;             // breathing room from canvas edge
$panelRad   = 36;             // card corner radius
$panelX1    = $margin;
$panelY1    = $margin;
$panelX2    = OG_WIDTH - $margin;
$panelY2    = OG_HEIGHT - $margin;

// Soft drop shadow: a slightly offset, darker rounded rect behind the panel.
$shadow = imagecolorallocatealpha($canvas, 0, 0, 0, 95);
if ($shadow !== false) {
    fillRoundedRect($canvas, $panelX1 + 6, $panelY1 + 10, $panelX2 + 6, $panelY2 + 10, $panelRad, $shadow);
}

fillRoundedRect($canvas, $panelX1, $panelY1, $panelX2, $panelY2, $panelRad, $white);

// ---------------------------------------------------------------------------
// 🔵 Composite the logo mark on the left of the card, scaled to fit nicely
//     with the transparent background preserved.
// ---------------------------------------------------------------------------

$logoMark = imagecreatefrompng($logoMarkPath);

if ($logoMark === false) {
    fwrite(STDERR, "ERROR: Failed to load logo mark PNG.\n");
    exit(1);
}

$srcW = imagesx($logoMark);
$srcH = imagesy($logoMark);

// Target mark height: a strong-but-restrained portion of the card height so
// the wordmark column has room to breathe on the right.
$markTarget = 300;
$markScale  = $markTarget / $srcH;
$markW      = (int) round($srcW * $markScale);
$markH      = $markTarget;

// Left-anchored within the card, vertically centred.
$markX = $panelX1 + 56;
$markY = (int) round((OG_HEIGHT - $markH) / 2);

// Preserve the mark's transparency when copying onto the white panel.
imagealphablending($canvas, true);
imagecopyresampled(
    $canvas,
    $logoMark,
    $markX,
    $markY,
    0,
    0,
    $markW,
    $markH,
    $srcW,
    $srcH
);

imagedestroy($logoMark);

// ---------------------------------------------------------------------------
// 🔤 Render the wordmark + tagline to the right of the mark.
// ---------------------------------------------------------------------------

$navyColour  = allocateHex($canvas, $navy);
$cyanColour  = allocateHex($canvas, $cyan);
$slateColour = allocateHex($canvas, $slate);

// Text column starts just right of the mark and must end inside the panel
// with a comfortable right-hand margin.
$textX        = $markX + $markW + 56;
$textRightPad = 56;
$maxTextWidth = $panelX2 - $textRightPad - $textX;

/**
 * Returns the largest point size (<= $startSize) at which $text rendered in
 * $font fits within $maxWidth pixels. Steps down a point at a time so the
 * card always fits regardless of the installed font's metrics.
 *
 * @param string $text      The string to measure.
 * @param string $font      Absolute path to the TrueType font.
 * @param int    $maxWidth  Maximum allowed rendered width in pixels.
 * @param int    $startSize Largest point size to consider.
 * @param int    $minSize   Smallest acceptable point size.
 */
function fitFontSize(string $text, string $font, int $maxWidth, int $startSize, int $minSize): int
{
    for ($size = $startSize; $size > $minSize; $size--) {
        $box   = imagettfbbox($size, 0, $font, $text);
        $width = abs($box[2] - $box[0]);

        if ($width <= $maxWidth) {
            return $size;
        }
    }

    return $minSize;
}

// "CueRCode" wordmark — large and bold, auto-fitted to the column.
$wordmark     = 'CueRCode';
$wordmarkSize = fitFontSize($wordmark, $boldFont, $maxTextWidth, 92, 40);

// Measure so we can vertically arrange the block as a unit.
$wmBox = imagettfbbox($wordmarkSize, 0, $boldFont, $wordmark);
$wmH   = abs($wmBox[7] - $wmBox[1]); // top to bottom

$tagline     = 'Advanced QR Code Generator';
$taglineSize = fitFontSize($tagline, $regularFont, $maxTextWidth, 36, 18);
$tagBox      = imagettfbbox($taglineSize, 0, $regularFont, $tagline);
$tagH        = abs($tagBox[7] - $tagBox[1]);

// Vertical layout: stack wordmark, accent rule, tagline, centred as a group.
$gapWmToRule = 28;
$ruleH       = 6;
$gapRuleToTag = 30;
$blockH = $wmH + $gapWmToRule + $ruleH + $gapRuleToTag + $tagH;
$blockTop = (int) round((OG_HEIGHT - $blockH) / 2);

// imagettftext y is the text baseline, so add the ascent of each line.
$wmBaselineY = $blockTop + $wmH;
imagettftext($canvas, $wordmarkSize, 0, $textX, $wmBaselineY, $navyColour, $boldFont, $wordmark);

// Accent rule in brand cyan, width tied to the wordmark's measured width.
$wmWidth = abs($wmBox[2] - $wmBox[0]);
$ruleY1  = $wmBaselineY + $gapWmToRule;
imagefilledrectangle($canvas, $textX, $ruleY1, $textX + (int) round($wmWidth * 0.55), $ruleY1 + $ruleH, $cyanColour);

// Tagline below the rule.
$tagBaselineY = $ruleY1 + $ruleH + $gapRuleToTag + $tagH;
imagettftext($canvas, $taglineSize, 0, $textX, $tagBaselineY, $slateColour, $regularFont, $tagline);

// ---------------------------------------------------------------------------
// 💾 Emit the PNG.
// ---------------------------------------------------------------------------

// Flatten alpha into the opaque background (no per-pixel alpha in output).
imagesavealpha($canvas, false);

// Use a high zlib compression level (0=none .. 9=max) to keep the file small
// while staying lossless.
$ok = imagepng($canvas, $outputPath, 9);

imagedestroy($canvas);

if ($ok === false) {
    fwrite(STDERR, "ERROR: Failed to write PNG to {$outputPath}\n");
    exit(1);
}

$bytes = filesize($outputPath);
$info  = getimagesize($outputPath);

printf(
    "OK: wrote %s\n  dimensions: %dx%d  type: %s  size: %d bytes (%.1f KB)\n",
    $outputPath,
    $info[0],
    $info[1],
    $info['mime'],
    $bytes,
    $bytes / 1024
);

exit(0);
