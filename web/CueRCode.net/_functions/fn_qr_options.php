<?php
/**
 * ==============================================================================
 * ⚙️ CueRCode - QR Code Options Builder
 * ==============================================================================
 *
 * Provides functions for mapping UI customization choices (colors, format,
 * size, pattern, logo space, gradient, etc.) to a QROptions object from
 * the chillerlan/php-qrcode v5.x library.
 *
 * The main function buildQROptions() accepts an associative array of
 * customization choices and returns a fully configured QROptions instance
 * ready for use with the QRCode class.
 *
 * Helper functions include color conversion (hex <-> RGB), scale calculation,
 * and output format mapping.
 *
 * @package     CueRCode
 * @subpackage  QR Generation
 * @version     1.0.0
 * @since       Phase 2
 * @see         https://php-qrcode.readthedocs.io/ chillerlan/php-qrcode documentation
 * @see         https://github.com/chillerlan/php-qrcode chillerlan/php-qrcode GitHub
 * @see         https://www.php.net/manual/en/function.hexdec.php
 * @see         https://www.php.net/manual/en/function.dechex.php
 */

// ==============================================================================
// 🚫 Direct access protection
// ==============================================================================
if (isset($_SERVER['SCRIPT_FILENAME'])) {
    $scriptFilenameValue = $_SERVER['SCRIPT_FILENAME'];
} else {
    $scriptFilenameValue = '';
}
if (basename($scriptFilenameValue) === basename(__FILE__)) {
    header('HTTP/1.1 403 Forbidden');
    exit('Access denied.');
}

// ==============================================================================
// 📦 Import QR code library classes
// ==============================================================================
// These use statements map the fully-qualified class names to short aliases.
// The autoloader (loaded in bootstrap.php) resolves these to actual files.
// @see https://www.php.net/manual/en/language.namespaces.importing.php
// ==============================================================================

use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Data\QRMatrix;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\Output\QRGdImageJPG;
use chillerlan\QRCode\Output\QRGdImageGIF;
use chillerlan\QRCode\Output\QRGdImageWEBP;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\Output\QRFpdf;

// ==============================================================================
// ⚙️ buildQROptions() - Main Options Builder
// ==============================================================================

/**
 * ⚙️ Build a QROptions object from UI customization choices
 *
 * Maps user-facing customization settings (from the QR code builder UI)
 * to the chillerlan/php-qrcode QROptions properties. This is the main
 * bridge between the UI and the QR generation library.
 *
 * Supported customization keys:
 *   - 'format'        (string)  Output format: png, jpg, gif, webp, svg, pdf (default: png)
 *   - 'size'          (int)     Target QR code size in pixels (default: 800)
 *   - 'ecc'           (string)  Error correction level: L, M, Q, H (default: M)
 *   - 'fg_color'      (string)  Foreground (dark module) color as hex (#000000)
 *   - 'bg_color'      (string)  Background (light module) color as hex (#ffffff)
 *   - 'marker_color'  (string)  Finder pattern (corner squares) color as hex
 *   - 'pattern'       (string)  Module shape: square, round, dot (default: square)
 *   - 'marker_border' (string)  Finder outer-border shape: square, round, circle, extra-rounded (default: square)
 *   - 'marker_center' (string)  Finder centre-dot shape: square, round, circle, diamond (default: square)
 *   - 'logo'          (mixed)   Logo identifier (triggers logo space reservation)
 *   - 'logo_size_pct' (int)     Logo size as percentage of QR code (default: 20)
 *   - 'transparent'   (bool)    Whether background should be transparent (default: false)
 *   - 'quiet_zone'    (bool)    Whether to add the quiet-zone margin (default: true)
 *   - 'gradient'      (array)   Gradient config: [type, color1, color2, direction] (SVG only)
 *   - 'payload'       (string)  The data payload (used for scale calculation)
 *
 * @param array $customization Associative array of customization choices
 *
 * @return \chillerlan\QRCode\QROptions The configured QROptions instance
 *
 * @see https://php-qrcode.readthedocs.io/en/main/Usage/Configuration.html
 * @see https://github.com/chillerlan/php-qrcode
 *
 * @example
 *   $options = buildQROptions([
 *       'format'   => 'png',
 *       'size'     => 1000,
 *       'ecc'      => 'H',
 *       'fg_color' => '#333333',
 *       'bg_color' => '#ffffff',
 *       'pattern'  => 'round',
 *   ]);
 *   $qr = new \chillerlan\QRCode\QRCode($options);
 */
function buildQROptions(array $customization): QROptions
{
    // ==================================================================
    // 📌 Extract and normalize customization values with defaults
    // ==================================================================

    // 📌 Output format (png, jpg, gif, webp, svg, pdf)
    if (isset($customization['format'])) {
        $formatValue = $customization['format'];
    } else {
        $formatValue = 'png';
    }
    $format = strtolower(trim($formatValue));

    // 📌 Target size in pixels (used to calculate scale)
    if (isset($customization['size'])) {
        $targetSize = (int) $customization['size'];
    } else {
        $targetSize = 800;
    }
    $targetSize = max(100, min($targetSize, 8000));

    // 📌 Error correction level (L, M, Q, H)
    if (isset($customization['ecc'])) {
        $eccValue = $customization['ecc'];
    } else {
        $eccValue = 'M';
    }
    $eccString = strtoupper(trim($eccValue));

    // 📌 Colors (hex strings with # prefix)
    if (isset($customization['fg_color'])) {
        $fgColorHexValue = $customization['fg_color'];
    } else {
        $fgColorHexValue = '#000000';
    }
    $fgColorHex = trim($fgColorHexValue);

    if (isset($customization['bg_color'])) {
        $bgColorHexValue = $customization['bg_color'];
    } else {
        $bgColorHexValue = '#ffffff';
    }
    $bgColorHex = trim($bgColorHexValue);

    if (isset($customization['marker_color'])) {
        $markerColorHexValue = $customization['marker_color'];
    } else {
        $markerColorHexValue = '';
    }
    $markerColorHex = trim($markerColorHexValue);

    // 📌 Module pattern style (square, round, dot)
    if (isset($customization['pattern'])) {
        $patternValue = $customization['pattern'];
    } else {
        $patternValue = 'square';
    }
    $pattern = strtolower(trim($patternValue));

    // 📌 Marker (finder pattern) outer border shape (square, round, circle, extra-rounded)
    // 🔧 PARITY: the API/UI offer a finder-pattern border shape. The chillerlan
    //    GD + markup outputs used here do not render per-finder corner radii, but
    //    a NON-square marker border IS meaningful when the modules are circular:
    //    it tells us to let the finder patterns be rounded too (i.e. NOT force
    //    them back to squares). We map the choice onto keepAsSquare below.
    if (isset($customization['marker_border'])) {
        $markerBorderValue = $customization['marker_border'];
    } else {
        $markerBorderValue = 'square';
    }
    $markerBorder = strtolower(trim((string) $markerBorderValue));

    // 📌 Marker (finder pattern) centre dot shape (square, round, circle, diamond)
    if (isset($customization['marker_center'])) {
        $markerCenterValue = $customization['marker_center'];
    } else {
        $markerCenterValue = 'square';
    }
    $markerCenter = strtolower(trim((string) $markerCenterValue));

    // 📌 Logo space configuration
    $hasLogo = !empty($customization['logo']);
    if (isset($customization['logo_size_pct'])) {
        $logoSizePct = (int) $customization['logo_size_pct'];
    } else {
        $logoSizePct = 20;
    }
    $logoSizePct = max(5, min($logoSizePct, 40));

    // 📌 Transparency
    if (isset($customization['transparent'])) {
        $transparent = (bool) $customization['transparent'];
    } else {
        $transparent = false;
    }

    // 📌 Quiet zone (margin) toggle. Defaults to true (matches the chillerlan
    //    library default $addQuietzone = true and the UI's default-checked box).
    // 🔧 PARITY: the UI offers a quiet-zone checkbox; honour it here so an API
    //    caller can disable the margin (e.g. for tight layouts) exactly as the
    //    web UI does. Mapped to the addQuietzone QROptions property below.
    if (isset($customization['quiet_zone'])) {
        $addQuietzone = (bool) $customization['quiet_zone'];
    } else {
        $addQuietzone = true;
    }

    // 📌 Gradient configuration (SVG only)
    if (isset($customization['gradient'])) {
        $gradient = $customization['gradient'];
    } else {
        $gradient = null;
    }

    // 📌 Payload data (used for scale calculation)
    if (isset($customization['payload'])) {
        $payload = $customization['payload'];
    } else {
        $payload = '';
    }

    // ==================================================================
    // 📋 Map output format to library classes and constants
    // @see https://php-qrcode.readthedocs.io/en/main/Usage/Output.html
    // ==================================================================
    $formatMapping = mapOutputFormat($format);

    // ==================================================================
    // 📐 Map error correction level to EccLevel constant
    // Higher ECC allows more data recovery but increases QR code density.
    // @see https://php-qrcode.readthedocs.io/en/main/Usage/Configuration.html
    // ==================================================================
    $eccLevel = mapEccLevel($eccString);

    // ==================================================================
    // 📏 Calculate the scale factor
    // Scale determines module size in pixels. We calculate it from the
    // target size divided by the estimated module count for the data.
    // @see https://php-qrcode.readthedocs.io/en/main/Usage/Configuration.html
    // ==================================================================
    $scale = calculateScale($targetSize, $eccLevel, strlen($payload));

    // ==================================================================
    // 🎨 Build the QROptions configuration array
    // @see https://php-qrcode.readthedocs.io/en/main/Usage/Configuration.html
    // ==================================================================
    $optionsArray = [
        'outputInterface' => $formatMapping['outputInterface'],
        'scale'           => $scale,
        'eccLevel'        => $eccLevel,
        'imageBase64'     => false,
        // 🔧 PARITY: honour the quiet-zone toggle. When false the library omits
        //    the 4-module margin; true (default) keeps it for scan reliability.
        // @see chillerlan\QRCode\QROptionsTrait::$addQuietzone
        'addQuietzone'    => $addQuietzone,
    ];

    // 📌 Set output type if applicable (GD image formats need outputType)
    if (isset($formatMapping['outputType'])) {
        $optionsArray['outputType'] = $formatMapping['outputType'];
    }

    // ==================================================================
    // 🎨 Configure colors based on output format
    // GD formats use [r, g, b] arrays; SVG uses hex strings.
    // @see https://php-qrcode.readthedocs.io/en/main/Usage/Configuration.html
    // ==================================================================
    $isSvg = ($format === 'svg');

    if ($isSvg) {
        // 📌 SVG: use hex colour strings directly
        $optionsArray['bgColor'] = $bgColorHex;
    } else {
        // 📌 GD (raster): use [r, g, b] arrays
        $bgRgb = hexToRgb($bgColorHex);
        $optionsArray['bgColor'] = $bgRgb;
    }

    // ==================================================================
    // 🎨 Configure module values (dark/light module colours)
    // This sets the color for each individual module type in the QR code.
    // @see https://php-qrcode.readthedocs.io/en/main/Usage/Configuration.html
    // ==================================================================
    $moduleValues = buildModuleValues($fgColorHex, $bgColorHex, $markerColorHex, $isSvg);
    $optionsArray['moduleValues'] = $moduleValues;

    // ==================================================================
    // ⭕ Configure module patterns (square vs round/dot)
    // When using round modules, finder patterns should remain square
    // for reliable scanning by QR readers.
    // @see https://php-qrcode.readthedocs.io/en/main/Usage/Configuration.html
    // ==================================================================
    if ($pattern === 'round' || $pattern === 'dot') {
        $optionsArray['drawCircularModules'] = true;
        $optionsArray['circleRadius']        = 0.45;

        // 📌 By default keep finder patterns (corner squares) as squares for
        //    scanner compatibility. These module types define the three large
        //    corner squares (outer border = M_FINDER_DARK / M_FINDER; centre dot
        //    = M_FINDER_DOT).
        // 🔧 PARITY: the marker_border / marker_center shape choices decide
        //    whether those finder module types are kept square or allowed to be
        //    rounded along with the data modules. A non-square border shape
        //    (round|circle|extra-rounded) lets the OUTER finder modules round;
        //    a non-square centre shape (round|circle|diamond) lets the CENTRE
        //    dot round. Anything left as 'square' stays square.
        // @see https://php-qrcode.readthedocs.io/en/main/Usage/Configuration.html
        $keepAsSquare = [];

        if ($markerBorder === 'square') {
            $keepAsSquare[] = QRMatrix::M_FINDER_DARK;
            $keepAsSquare[] = QRMatrix::M_FINDER;
        }

        if ($markerCenter === 'square') {
            $keepAsSquare[] = QRMatrix::M_FINDER_DOT;
        }

        $optionsArray['keepAsSquare'] = $keepAsSquare;
    } else {
        // 📌 Default square modules
        $optionsArray['drawCircularModules'] = false;
    }

    // ==================================================================
    // 🖼️ Configure logo space reservation
    // When a logo will be overlaid, reserve a blank area in the center
    // of the QR code so data modules are not placed under the logo.
    // @see https://php-qrcode.readthedocs.io/en/main/Usage/Configuration.html
    // ==================================================================
    if ($hasLogo) {
        $optionsArray['addLogoSpace'] = true;

        // 📌 Calculate logo space in modules based on percentage
        // Estimate the QR code module count based on version/ECC level.
        // A reasonable default is ~25-40 modules per side depending on data.
        $estimatedModules = estimateModuleCount($eccLevel, strlen($payload));
        $logoModules      = max(3, (int) round($estimatedModules * ($logoSizePct / 100)));

        $optionsArray['logoSpaceWidth']  = $logoModules;
        $optionsArray['logoSpaceHeight'] = $logoModules;
    }

    // ==================================================================
    // 🔲 Configure transparency (PNG, GIF, WEBP only)
    // @see https://php-qrcode.readthedocs.io/en/main/Usage/Configuration.html
    // ==================================================================
    $transparentFormats = ['png', 'gif', 'webp'];

    if ($transparent && in_array($format, $transparentFormats, true)) {
        $optionsArray['imageTransparent'] = true;
    }

    // ==================================================================
    // 🌈 Configure gradient (SVG only)
    // SVG gradients are applied via the svgDefs property which injects
    // custom SVG <defs> elements (linearGradient or radialGradient).
    // @see https://developer.mozilla.org/en-US/docs/Web/SVG/Element/linearGradient
    // @see https://developer.mozilla.org/en-US/docs/Web/SVG/Element/radialGradient
    // ==================================================================
    if ($isSvg && $gradient !== null && is_array($gradient)) {
        $svgDefs = buildSvgGradientDefs($gradient);
        if ($svgDefs !== '') {
            $optionsArray['svgDefs'] = $svgDefs;
        }
    }

    // ==================================================================
    // 📦 Create and return the QROptions instance
    // @see https://php-qrcode.readthedocs.io/en/main/Usage/Configuration.html
    // ==================================================================
    try {
        $options = new QROptions($optionsArray);
    } catch (\Throwable $e) {
        // ❌ QROptions creation failed - log and rethrow
        if (function_exists('logError')) {
            logError(
                'error',
                'QROptions creation failed',
                $e->getMessage(),
                ['customization' => $customization]
            );
        }
        throw new \RuntimeException('Failed to create QR code options: ' . $e->getMessage(), 0, $e);
    }

    return $options;
}

// ==============================================================================
// 🎨 hexToRgb() - Convert hex colour to RGB array
// ==============================================================================

/**
 * 🎨 Convert a hexadecimal colour string to an RGB array
 *
 * Supports 3-digit (#fff), 6-digit (#ffffff), and 8-digit (#ffffffaa)
 * hex colour codes. The '#' prefix is optional.
 *
 * @param string $hex The hex colour string (e.g., '#ff0000', 'ff0000', '#f00')
 *
 * @return array Indexed array [red, green, blue] with values 0-255
 *
 * @see https://www.php.net/manual/en/function.hexdec.php
 * @see https://www.php.net/manual/en/function.substr.php
 *
 * @example
 *   hexToRgb('#ff0000');  // Returns: [255, 0, 0]
 *   hexToRgb('#fff');     // Returns: [255, 255, 255]
 *   hexToRgb('00ff00');   // Returns: [0, 255, 0]
 */
function hexToRgb(string $hex): array
{
    // 📌 Remove the '#' prefix if present
    // @see https://www.php.net/manual/en/function.ltrim.php
    $hex = ltrim(trim($hex), '#');

    // 📌 Handle 3-digit shorthand (#fff -> #ffffff)
    // @see https://developer.mozilla.org/en-US/docs/Web/CSS/hex-color
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }

    // 📌 Handle 8-digit with alpha (#ffffffaa) - strip the alpha channel
    if (strlen($hex) === 8) {
        $hex = substr($hex, 0, 6);
    }

    // 📌 Validate hex string length
    if (strlen($hex) !== 6) {
        // ⚠️ Invalid hex - return black as fallback
        return [0, 0, 0];
    }

    // 📌 Convert hex pairs to decimal integers
    // @see https://www.php.net/manual/en/function.hexdec.php
    $r = (int) hexdec(substr($hex, 0, 2));
    $g = (int) hexdec(substr($hex, 2, 2));
    $b = (int) hexdec(substr($hex, 4, 2));

    return [$r, $g, $b];
}

// ==============================================================================
// 🎨 rgbToHex() - Convert RGB values to hex colour string
// ==============================================================================

/**
 * 🎨 Convert RGB integer values to a hexadecimal colour string
 *
 * @param int $r Red channel value (0-255)
 * @param int $g Green channel value (0-255)
 * @param int $b Blue channel value (0-255)
 *
 * @return string The hex colour string with '#' prefix (e.g., '#ff0000')
 *
 * @see https://www.php.net/manual/en/function.sprintf.php
 * @see https://www.php.net/manual/en/function.dechex.php
 *
 * @example
 *   rgbToHex(255, 0, 0);     // Returns: '#ff0000'
 *   rgbToHex(0, 128, 255);   // Returns: '#0080ff'
 */
function rgbToHex(int $r, int $g, int $b): string
{
    // 📌 Clamp values to 0-255 range
    // @see https://www.php.net/manual/en/function.max.php
    // @see https://www.php.net/manual/en/function.min.php
    $r = max(0, min(255, $r));
    $g = max(0, min(255, $g));
    $b = max(0, min(255, $b));

    // 📌 Format as 6-digit hex string with '#' prefix
    // %02x pads each value to 2 hex digits with leading zero if needed
    // @see https://www.php.net/manual/en/function.sprintf.php
    return sprintf('#%02x%02x%02x', $r, $g, $b);
}

// ==============================================================================
// 📏 calculateScale() - Calculate QR code scale from target size
// ==============================================================================

/**
 * 📏 Calculate the QR code scale factor from a target pixel size
 *
 * The scale factor determines the pixel size of each QR code module.
 * It is calculated by dividing the target image size by the estimated
 * number of modules (including quiet zone) on one side of the QR code.
 *
 * QR code version determines the module grid size:
 *   - Version 1:  21x21 modules
 *   - Version 10: 57x57 modules
 *   - Version 40: 177x177 modules
 *   - Formula: 4 * version + 17
 *
 * @param int $targetSize The desired QR code size in pixels
 * @param int $eccLevel   The error correction level (EccLevel constant value)
 * @param int $dataLength The length of the data payload in bytes
 *
 * @return int The calculated scale factor (minimum 1, maximum 50)
 *
 * @see https://www.qrcode.com/en/about/version.html QR code version information
 * @see https://www.php.net/manual/en/function.max.php
 * @see https://www.php.net/manual/en/function.min.php
 * @see https://www.php.net/manual/en/function.ceil.php
 *
 * @example
 *   calculateScale(800, EccLevel::M, 50);   // Returns approximately 20
 *   calculateScale(300, EccLevel::H, 100);   // Returns approximately 5
 */
function calculateScale(int $targetSize, int $eccLevel, int $dataLength): int
{
    // 📌 Estimate the QR code module count (modules per side)
    $moduleCount = estimateModuleCount($eccLevel, $dataLength);

    // 📌 Add quiet zone (4 modules on each side per QR spec)
    // @see https://www.qrcode.com/en/about/version.html
    $totalModules = $moduleCount + 8;

    // 📌 Calculate scale: target size / total modules
    // @see https://www.php.net/manual/en/function.max.php
    if ($totalModules <= 0) {
        return 10;
    }

    $scale = (int) floor($targetSize / $totalModules);

    // 📌 Clamp scale to a reasonable range
    // Minimum 1 (1px per module), maximum 50 (50px per module)
    return max(1, min(50, $scale));
}

// ==============================================================================
// 📐 estimateModuleCount() - Estimate QR code module grid size
// ==============================================================================

/**
 * 📐 Estimate the number of modules per side for a QR code
 *
 * Uses a simplified estimation based on data length and error correction
 * level to predict which QR code version will be needed, then calculates
 * the module grid size (4 * version + 17).
 *
 * This is an estimate - the actual version may differ slightly based on
 * encoding mode (numeric vs alphanumeric vs byte).
 *
 * @param int $eccLevel   The error correction level (EccLevel constant value)
 * @param int $dataLength The length of the data payload in bytes
 *
 * @return int Estimated number of modules per side (21-177)
 *
 * @see https://www.qrcode.com/en/about/version.html QR code version table
 */
function estimateModuleCount(int $eccLevel, int $dataLength): int
{
    // 📌 Simplified version estimation based on data capacity (byte mode)
    // These are approximate byte-mode capacities for each ECC level at various versions.
    // @see https://www.qrcode.com/en/about/version.html
    //
    // The capacity arrays map: [version => max_bytes_at_ecc_level]
    // We use a simplified lookup with key thresholds.

    // 📌 Byte-mode capacities at different ECC levels (selected versions)
    // Format: [max_bytes => version]
    // The rows are keyed by the LOGICAL ECC name (L/M/Q/H), NOT by the library's
    // numeric EccLevel constant — those constants are deliberately bit-packed and
    // are NOT in L,M,Q,H order (L=1, M=0, Q=3, H=2). See the mapping below.
    // @see https://www.qrcode.com/en/about/version.html
    $capacityTable = [
        // EccLevel L (approximately 7% recovery)
        'L' => [
            17  => 1,   41  => 2,   77  => 3,   114 => 4,   154 => 5,
            195 => 6,   224 => 7,   279 => 8,   335 => 9,   395 => 10,
            512 => 12,  652 => 14,  818 => 16,  1003 => 18, 1249 => 20,
            1574 => 23, 1852 => 25, 2232 => 28, 2603 => 30, 2956 => 32,
            3391 => 35, 3909 => 38, 4296 => 40,
        ],
        // EccLevel M (approximately 15% recovery)
        'M' => [
            14  => 1,   32  => 2,   53  => 3,   78  => 4,   106 => 5,
            134 => 6,   154 => 7,   192 => 8,   230 => 9,   271 => 10,
            367 => 12,  458 => 14,  570 => 16,  700 => 18,  871 => 20,
            1089 => 23, 1273 => 25, 1528 => 28, 1732 => 30, 2009 => 32,
            2324 => 35, 2670 => 38, 2956 => 40,
        ],
        // EccLevel Q (approximately 25% recovery)
        'Q' => [
            11  => 1,   24  => 2,   40  => 3,   54  => 4,   74  => 5,
            97  => 6,   111 => 7,   135 => 8,   160 => 9,   192 => 10,
            259 => 12,  321 => 14,  401 => 16,  489 => 18,  604 => 20,
            762 => 23,  894 => 25,  1062 => 28, 1222 => 30, 1415 => 32,
            1628 => 35, 1867 => 38, 2068 => 40,
        ],
        // EccLevel H (approximately 30% recovery)
        'H' => [
            7   => 1,   17  => 2,   32  => 3,   42  => 4,   56  => 5,
            74  => 6,   86  => 7,   108 => 8,   130 => 9,   151 => 10,
            203 => 12,  253 => 14,  313 => 16,  385 => 18,  468 => 20,
            587 => 23,  691 => 25,  816 => 28,  953 => 30,  1101 => 32,
            1276 => 35, 1455 => 38, 1588 => 40,
        ],
    ];

    // 📌 Map the library's numeric EccLevel constant to the correct capacity row.
    // 🐛 FIX (2026-06-28): the rows were previously keyed 0=L,1=M,2=Q,3=H and
    //    indexed with the raw EccLevel value. But chillerlan's EccLevel constants
    //    are L=1, M=0, Q=3, H=2, so the raw value selected the WRONG row for every
    //    level (e.g. ecc=L (1) picked the M row, ecc=H (2) picked the Q row). We
    //    now translate the constant to its logical L/M/Q/H name and look that up.
    //    Affects scale ESTIMATION only — the generated QR is unaffected.
    // @see web/CueRCode.net/_libraries/chillerlan/php-qrcode/src/Common/EccLevel.php
    $eccRowMap = [
        EccLevel::L => 'L',
        EccLevel::M => 'M',
        EccLevel::Q => 'Q',
        EccLevel::H => 'H',
    ];

    if (isset($eccRowMap[$eccLevel])) {
        $eccRowKey = $eccRowMap[$eccLevel];
    } else {
        // ⚠️ Unknown ECC value — fall back to the M (Medium) capacity row.
        $eccRowKey = 'M';
    }

    $table = $capacityTable[$eccRowKey];

    // 📌 Find the smallest version that can hold the data
    $version = 1;
    foreach ($table as $maxBytes => $ver) {
        if ($dataLength <= $maxBytes) {
            $version = $ver;
            break;
        }
        $version = $ver;
    }

    // 📌 Calculate module count from version: 4 * version + 17
    // @see https://www.qrcode.com/en/about/version.html
    return (4 * $version) + 17;
}

// ==============================================================================
// 📋 mapOutputFormat() - Map format string to library classes
// ==============================================================================

/**
 * 📋 Map a user-facing format string to QR library output classes and types
 *
 * Returns an associative array containing the output interface class,
 * output type constant, MIME type, and file extension for the specified
 * format.
 *
 * @param string $format The output format: png, jpg, gif, webp, svg, pdf
 *
 * @return array Associative array with keys:
 *   - 'outputInterface' (string) Fully-qualified class name
 *   - 'outputType'      (string) QROutputInterface constant (for GD formats)
 *   - 'mimeType'        (string) MIME type string
 *   - 'fileExtension'   (string) File extension without dot
 *
 * @see https://php-qrcode.readthedocs.io/en/main/Usage/Output.html
 *
 * @example
 *   $mapping = mapOutputFormat('png');
 *   // Returns: [
 *   //   'outputInterface' => QRGdImagePNG::class,
 *   //   'outputType'      => QROutputInterface::GDIMAGE_PNG,
 *   //   'mimeType'        => 'image/png',
 *   //   'fileExtension'   => 'png',
 *   // ]
 */
function mapOutputFormat(string $format): array
{
    // 📋 Format mapping table
    // Maps user-facing format strings to the chillerlan/php-qrcode output classes.
    // @see https://php-qrcode.readthedocs.io/en/main/Usage/Output.html
    $formatMap = [

        // 🖼️ PNG: Portable Network Graphics (lossless, supports transparency)
        // Uses GD library for rasterisation.
        // @see https://www.php.net/manual/en/function.imagepng.php
        'png' => [
            'outputInterface' => QRGdImagePNG::class,
            'outputType'      => QROutputInterface::GDIMAGE_PNG,
            'mimeType'        => 'image/png',
            'fileExtension'   => 'png',
        ],

        // 📷 JPG/JPEG: Joint Photographic Experts Group (lossy, no transparency)
        // Uses GD library for rasterisation.
        // @see https://www.php.net/manual/en/function.imagejpeg.php
        'jpg' => [
            'outputInterface' => QRGdImageJPG::class,
            'outputType'      => QROutputInterface::GDIMAGE_JPG,
            'mimeType'        => 'image/jpeg',
            'fileExtension'   => 'jpg',
        ],
        'jpeg' => [
            'outputInterface' => QRGdImageJPG::class,
            'outputType'      => QROutputInterface::GDIMAGE_JPG,
            'mimeType'        => 'image/jpeg',
            'fileExtension'   => 'jpg',
        ],

        // 🎞️ GIF: Graphics Interchange Format (limited colour, supports transparency)
        // Uses GD library for rasterisation.
        // @see https://www.php.net/manual/en/function.imagegif.php
        'gif' => [
            'outputInterface' => QRGdImageGIF::class,
            'outputType'      => QROutputInterface::GDIMAGE_GIF,
            'mimeType'        => 'image/gif',
            'fileExtension'   => 'gif',
        ],

        // 🌐 WEBP: Google WebP (modern format, good compression, supports transparency)
        // Uses GD library for rasterisation. Requires PHP 7.1+ with WebP support.
        // @see https://www.php.net/manual/en/function.imagewebp.php
        'webp' => [
            'outputInterface' => QRGdImageWEBP::class,
            'outputType'      => QROutputInterface::GDIMAGE_WEBP,
            'mimeType'        => 'image/webp',
            'fileExtension'   => 'webp',
        ],

        // 📐 SVG: Scalable Vector Graphics (vector, infinite scaling, CSS styling)
        // Uses markup generation (no GD required).
        // @see https://developer.mozilla.org/en-US/docs/Web/SVG
        'svg' => [
            'outputInterface' => QRMarkupSVG::class,
            'outputType'      => null,
            'mimeType'        => 'image/svg+xml',
            'fileExtension'   => 'svg',
        ],

        // 📄 PDF: Portable Document Format (via FPDF library)
        // Requires the FPDF library to be available.
        // @see http://www.fpdf.org/
        'pdf' => [
            'outputInterface' => QRFpdf::class,
            'outputType'      => null,
            'mimeType'        => 'application/pdf',
            'fileExtension'   => 'pdf',
        ],
    ];

    // 📌 Return the mapping for the requested format, defaulting to PNG
    if (isset($formatMap[$format])) {
        return $formatMap[$format];
    }

    // ⚠️ Unknown format - fall back to PNG and log a warning
    if (function_exists('logError')) {
        logError(
            'warning',
            'Unknown QR output format',
            'Requested format "' . $format . '" is not supported. Falling back to PNG.'
        );
    }

    return $formatMap['png'];
}

// ==============================================================================
// 🔒 mapEccLevel() - Map ECC string to EccLevel constant
// ==============================================================================

/**
 * 🔒 Map an error correction level string to an EccLevel constant value
 *
 * QR code error correction levels determine how much data can be recovered
 * if the QR code is damaged:
 *   - L (Low):      ~7% recovery
 *   - M (Medium):   ~15% recovery (default, recommended)
 *   - Q (Quartile): ~25% recovery
 *   - H (High):     ~30% recovery (required for logo overlays)
 *
 * @param string $eccString The ECC level string: L, M, Q, or H
 *
 * @return int The EccLevel constant value
 *
 * @see https://php-qrcode.readthedocs.io/en/main/Usage/Configuration.html
 * @see https://www.qrcode.com/en/about/error_correction.html
 *
 * @example
 *   mapEccLevel('H');  // Returns: EccLevel::H
 *   mapEccLevel('L');  // Returns: EccLevel::L
 */
function mapEccLevel(string $eccString): int
{
    // 📋 Map ECC strings to EccLevel constants
    // @see https://php-qrcode.readthedocs.io/en/main/Usage/Configuration.html
    $eccMap = [
        'L' => EccLevel::L,
        'M' => EccLevel::M,
        'Q' => EccLevel::Q,
        'H' => EccLevel::H,
    ];

    $eccString = strtoupper(trim($eccString));

    // 📌 Return the mapped value, defaulting to M (Medium) for unknown values
    if (isset($eccMap[$eccString])) {
        return $eccMap[$eccString];
    } else {
        return EccLevel::M;
    }
}

// ==============================================================================
// 🎨 buildModuleValues() - Build module colour values array
// ==============================================================================

/**
 * 🎨 Build the moduleValues array for QROptions
 *
 * The moduleValues array defines the color for each type of module in
 * the QR code (data modules, finder patterns, alignment patterns,
 * timing patterns, format info, version info, etc.).
 *
 * Dark modules are filled with the foreground colour.
 * Light modules are filled with the background colour.
 * If a marker colour is specified, finder pattern modules use that instead.
 *
 * @param string $fgColorHex     Foreground (dark) colour as hex string (#000000)
 * @param string $bgColorHex     Background (light) colour as hex string (#ffffff)
 * @param string $markerColorHex Finder pattern colour as hex (empty = use fg colour)
 * @param bool   $isSvg          Whether the output is SVG (uses hex strings vs RGB arrays)
 *
 * @return array The moduleValues array for QROptions
 *
 * @see https://php-qrcode.readthedocs.io/en/main/Usage/Configuration.html
 */
function buildModuleValues(
    string $fgColorHex,
    string $bgColorHex,
    string $markerColorHex,
    bool   $isSvg
): array {
    // 📌 Convert colours to the appropriate format for the output type
    if ($isSvg) {
        // 📌 SVG: use hex colour strings
        $darkColor  = $fgColorHex;
        $lightColor = $bgColorHex;
        if ($markerColorHex !== '') {
            $markerDark = $markerColorHex;
        } else {
            $markerDark = $fgColorHex;
        }
        $markerLight = $bgColorHex;
    } else {
        // 📌 GD (raster): use [r, g, b] arrays
        $darkColor   = hexToRgb($fgColorHex);
        $lightColor  = hexToRgb($bgColorHex);
        if ($markerColorHex !== '') {
            $markerDark = hexToRgb($markerColorHex);
        } else {
            $markerDark = hexToRgb($fgColorHex);
        }
        $markerLight = hexToRgb($bgColorHex);
    }

    // ==================================================================
    // 📋 Build the moduleValues mapping
    // Each QR code module type has a constant defined in QRMatrix.
    // Dark variants are filled, light variants are background.
    // @see https://php-qrcode.readthedocs.io/en/main/Usage/Configuration.html
    // ==================================================================
    $moduleValues = [

        // 📊 Data modules (the actual encoded data)
        QRMatrix::M_DATA_DARK => $darkColor,
        QRMatrix::M_DATA      => $lightColor,

        // 🔲 Finder patterns (the three large corner squares)
        // These use the marker colour if specified, otherwise the foreground colour.
        QRMatrix::M_FINDER_DARK => $markerDark,
        QRMatrix::M_FINDER      => $markerLight,
        QRMatrix::M_FINDER_DOT  => $markerDark,

        // 📐 Alignment patterns (small squares in larger QR codes)
        QRMatrix::M_ALIGNMENT_DARK => $darkColor,
        QRMatrix::M_ALIGNMENT      => $lightColor,

        // ⏱️ Timing patterns (alternating dark/light modules between finders)
        QRMatrix::M_TIMING_DARK => $darkColor,
        QRMatrix::M_TIMING      => $lightColor,

        // 📋 Format information (ECC level and mask pattern)
        QRMatrix::M_FORMAT_DARK => $darkColor,
        QRMatrix::M_FORMAT      => $lightColor,

        // 📋 Version information (QR code version for versions >= 7)
        QRMatrix::M_VERSION_DARK => $darkColor,
        QRMatrix::M_VERSION      => $lightColor,

        // ⬜ Separator (white border around finder patterns)
        QRMatrix::M_SEPARATOR => $lightColor,

        // ⬜ Quiet zone (white border around entire QR code)
        QRMatrix::M_QUIETZONE => $lightColor,
    ];

    return $moduleValues;
}

// ==============================================================================
// 🌈 buildSvgGradientDefs() - Build SVG gradient definitions
// ==============================================================================

/**
 * 🌈 Build SVG <defs> element for gradient fills
 *
 * Creates an SVG gradient definition that can be used to fill modules
 * with a linear or radial gradient instead of a solid colour.
 *
 * @param array $gradient Gradient configuration:
 *   - 'type'      (string) Gradient type: 'linear' or 'radial' (default: 'linear')
 *   - 'color1'    (string) Start colour as hex (default: '#000000')
 *   - 'color2'    (string) End colour as hex (default: '#333333')
 *   - 'direction' (string) Direction: 'horizontal', 'vertical', 'diagonal' (default: 'vertical')
 *
 * @return string The SVG <defs> element string, or empty string if invalid
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/SVG/Element/linearGradient
 * @see https://developer.mozilla.org/en-US/docs/Web/SVG/Element/radialGradient
 *
 * @example
 *   buildSvgGradientDefs([
 *       'type'      => 'linear',
 *       'color1'    => '#ff0000',
 *       'color2'    => '#0000ff',
 *       'direction' => 'vertical',
 *   ]);
 */
function buildSvgGradientDefs(array $gradient): string
{
    // 📌 Extract gradient parameters with defaults
    if (isset($gradient['type'])) {
        $gradTypeValue = $gradient['type'];
    } else {
        $gradTypeValue = 'linear';
    }
    $type = strtolower(trim($gradTypeValue));

    if (isset($gradient['color1'])) {
        $gradColor1Value = $gradient['color1'];
    } else {
        $gradColor1Value = '#000000';
    }
    $color1 = trim($gradColor1Value);

    if (isset($gradient['color2'])) {
        $gradColor2Value = $gradient['color2'];
    } else {
        $gradColor2Value = '#333333';
    }
    $color2 = trim($gradColor2Value);

    if (isset($gradient['direction'])) {
        $gradDirectionValue = $gradient['direction'];
    } else {
        $gradDirectionValue = 'vertical';
    }
    $direction = strtolower(trim($gradDirectionValue));

    // 📌 Escape colour values for safe SVG attribute inclusion
    // @see https://www.php.net/manual/en/function.htmlspecialchars.php
    $safeColor1 = htmlspecialchars($color1, ENT_QUOTES, 'UTF-8');
    $safeColor2 = htmlspecialchars($color2, ENT_QUOTES, 'UTF-8');

    // ==================================================================
    // 📐 Build gradient based on type
    // ==================================================================

    if ($type === 'radial') {
        // 📌 Radial gradient: centre outward
        // @see https://developer.mozilla.org/en-US/docs/Web/SVG/Element/radialGradient
        $svgDefs = '<defs>' . PHP_EOL
            . '  <radialGradient id="cuercode-gradient" cx="50%" cy="50%" r="50%">' . PHP_EOL
            . '    <stop offset="0%" stop-color="' . $safeColor1 . '"/>' . PHP_EOL
            . '    <stop offset="100%" stop-color="' . $safeColor2 . '"/>' . PHP_EOL
            . '  </radialGradient>' . PHP_EOL
            . '</defs>';
    } else {
        // 📌 Linear gradient: direction determines x1/y1/x2/y2 coordinates
        // @see https://developer.mozilla.org/en-US/docs/Web/SVG/Element/linearGradient
        switch ($direction) {
            case 'horizontal':
                $coords = ['x1' => '0%', 'y1' => '0%', 'x2' => '100%', 'y2' => '0%'];
                break;
            case 'diagonal':
                $coords = ['x1' => '0%', 'y1' => '0%', 'x2' => '100%', 'y2' => '100%'];
                break;
            default:
                // vertical
                $coords = ['x1' => '0%', 'y1' => '0%', 'x2' => '0%',   'y2' => '100%'];
                break;
        }

        $svgDefs = '<defs>' . PHP_EOL
            . '  <linearGradient id="cuercode-gradient"'
            . ' x1="' . $coords['x1'] . '"'
            . ' y1="' . $coords['y1'] . '"'
            . ' x2="' . $coords['x2'] . '"'
            . ' y2="' . $coords['y2'] . '">' . PHP_EOL
            . '    <stop offset="0%" stop-color="' . $safeColor1 . '"/>' . PHP_EOL
            . '    <stop offset="100%" stop-color="' . $safeColor2 . '"/>' . PHP_EOL
            . '  </linearGradient>' . PHP_EOL
            . '</defs>';
    }

    return $svgDefs;
}
