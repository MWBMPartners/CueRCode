<?php
/**
 * ==============================================================================
 * 🖼️ CueRCode - Logo Overlay for QR Codes
 * ==============================================================================
 *
 * Provides functions for overlaying logos (brand marks, icons, images) onto
 * generated QR codes. Supports both raster (PNG, JPG, GIF, WEBP) and vector
 * (SVG) QR code outputs.
 *
 * Logos can come from three sources:
 *   1. Predefined logos stored in tblPredefinedLogos (referenced by numeric ID)
 *   2. Base64-encoded uploads (data: URIs from the browser)
 *   3. Server file paths (for pre-uploaded logos)
 *
 * For raster QR codes, the logo is overlaid using GD library functions with
 * alpha channel preservation. For SVG QR codes, a base64-encoded <image>
 * element is inserted into the SVG markup.
 *
 * @package     CueRCode
 * @subpackage  QR Generation
 * @version     1.0.0
 * @since       Phase 2
 * @see         https://www.php.net/manual/en/book.image.php GD library documentation
 * @see         https://www.php.net/manual/en/function.imagecreatefromstring.php
 * @see         https://www.php.net/manual/en/function.imagecopyresampled.php
 * @see         https://developer.mozilla.org/en-US/docs/Web/SVG/Element/image SVG image element
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
// 🛡️ Resource limits for logo decode/processing (F-005)
// ==============================================================================
// Caps applied BEFORE any heavy image work (base64 decode -> disk -> GD) to
// prevent decompression / pixel "bombs": a small upload that decodes to a huge
// byte blob, or a tiny file that declares enormous pixel dimensions and would
// blow up memory when GD allocates the canvas. These are conservative, defensive
// caps that comfortably accommodate any real brand logo. Each is overridable via
// a define() set earlier (e.g. in bootstrap/config) so the owner can tune them
// without editing this file. Values follow this workstream's brief (2 MB decoded,
// 4096×4096 px); the SECURITY register's looser suggestion (8 MB / 8000²) can be
// adopted by defining the constants below.
// @see https://www.php.net/manual/en/function.getimagesize.php
if (!defined('CUERCODE_LOGO_MAX_DECODED_BYTES')) {
    // 📏 Max decoded image size in bytes (2 MB).
    define('CUERCODE_LOGO_MAX_DECODED_BYTES', 2 * 1024 * 1024);
}
if (!defined('CUERCODE_LOGO_MAX_WIDTH')) {
    // 📐 Max image width in pixels.
    define('CUERCODE_LOGO_MAX_WIDTH', 4096);
}
if (!defined('CUERCODE_LOGO_MAX_HEIGHT')) {
    // 📐 Max image height in pixels.
    define('CUERCODE_LOGO_MAX_HEIGHT', 4096);
}

// ==============================================================================
// 🖼️ overlayLogoOnRaster() - Overlay logo on raster QR code image
// ==============================================================================

/**
 * 🖼️ Overlay a logo image onto a raster (GD-based) QR code image
 *
 * Loads the QR code image from a binary string, loads the logo from a file
 * path, resizes the logo to the target percentage of the QR dimensions,
 * centers it, and composites it onto the QR code.
 *
 * Optionally adds a white circular or rounded padding area behind the logo
 * for better visibility against the dark QR code modules.
 *
 * The result is returned as a binary image string in the specified format.
 *
 * @param string $qrImageData The raw QR code image data (binary string)
 * @param string $logoPath    Absolute path to the logo image file
 * @param array  $config      Logo configuration:
 *   - 'size_pct'     (int)    Logo size as percentage of QR code width (default: 20)
 *   - 'padding'      (bool)   Whether to add white padding behind logo (default: true)
 *   - 'padding_pct'  (int)    Padding size as percentage of logo size (default: 15)
 *   - 'padding_shape'(string) Padding shape: 'circle', 'rounded', 'square' (default: 'circle')
 *   - 'output_format'(string) Output format: 'png', 'jpg', 'gif', 'webp' (default: 'png')
 *
 * @return string The composited image as a binary string in the specified format
 *
 * @throws \RuntimeException If GD library is not available or image processing fails
 *
 * @see https://www.php.net/manual/en/function.imagecreatefromstring.php
 * @see https://www.php.net/manual/en/function.imagecopyresampled.php
 * @see https://www.php.net/manual/en/function.imagesavealpha.php
 * @see https://www.php.net/manual/en/function.imagealphablending.php
 *
 * @example
 *   $qrWithLogo = overlayLogoOnRaster(
 *       $qrImageData,
 *       '/path/to/logo.png',
 *       ['size_pct' => 20, 'padding' => true, 'padding_shape' => 'circle']
 *   );
 */
function overlayLogoOnRaster(string $qrImageData, string $logoPath, array $config = []): string
{
    // ✅ Verify GD library is available
    // @see https://www.php.net/manual/en/book.image.php
    if (!extension_loaded('gd')) {
        throw new \RuntimeException(
            'GD library is required for raster logo overlay but is not installed. '
            . 'Enable the php-gd extension in your PHP configuration.'
        );
    }

    // 📌 Extract configuration with defaults
    if (isset($config['size_pct'])) {
        $sizePctRaw = (int) $config['size_pct'];
    } else {
        $sizePctRaw = 20;
    }
    $sizePct = max(5, min(40, $sizePctRaw));

    if (isset($config['padding'])) {
        $addPadding = (bool) $config['padding'];
    } else {
        $addPadding = true;
    }

    if (isset($config['padding_pct'])) {
        $paddingPctRaw = (int) $config['padding_pct'];
    } else {
        $paddingPctRaw = 15;
    }
    $paddingPct = max(0, min(50, $paddingPctRaw));

    if (isset($config['padding_shape'])) {
        $paddingShapeValue = $config['padding_shape'];
    } else {
        $paddingShapeValue = 'circle';
    }
    $paddingShape = strtolower(trim($paddingShapeValue));

    if (isset($config['output_format'])) {
        $outputFormatValue = $config['output_format'];
    } else {
        $outputFormatValue = 'png';
    }
    $outputFormat = strtolower(trim($outputFormatValue));

    // ==================================================================
    // 📷 Load the QR code image from binary string
    // @see https://www.php.net/manual/en/function.imagecreatefromstring.php
    // ==================================================================
    $qrImage = @imagecreatefromstring($qrImageData);

    if ($qrImage === false) {
        throw new \RuntimeException(
            'Failed to load QR code image data. The image data may be corrupted or in an unsupported format.'
        );
    }

    // 📌 Enable alpha blending for proper transparency handling
    // @see https://www.php.net/manual/en/function.imagealphablending.php
    imagealphablending($qrImage, true);
    // @see https://www.php.net/manual/en/function.imagesavealpha.php
    imagesavealpha($qrImage, true);

    // 📌 Get QR code dimensions
    // @see https://www.php.net/manual/en/function.imagesx.php
    $qrWidth  = imagesx($qrImage);
    $qrHeight = imagesy($qrImage);

    // ==================================================================
    // 🖼️ Load the logo image
    // @see https://www.php.net/manual/en/function.imagecreatefromstring.php
    // ==================================================================
    $logoImage = loadLogoImage($logoPath);

    if ($logoImage === null) {
        // ⚠️ Logo could not be loaded - return original QR code unchanged
        if (function_exists('logError')) {
            logError(
                'warning',
                'Logo image could not be loaded',
                'Path: ' . $logoPath
            );
        }

        // 📌 Return original QR image as string
        return renderGdImageToString($qrImage, $outputFormat);
    }

    // 📌 Get logo dimensions
    $logoOrigWidth  = imagesx($logoImage);
    $logoOrigHeight = imagesy($logoImage);

    // ==================================================================
    // 📐 Calculate logo target size
    // The logo should be {sizePct}% of the QR code width/height.
    // Aspect ratio is preserved (fit within the target area).
    // ==================================================================
    $targetLogoWidth  = (int) round($qrWidth * ($sizePct / 100));
    $targetLogoHeight = (int) round($qrHeight * ($sizePct / 100));

    // 📌 Preserve aspect ratio: fit within target dimensions
    // @see https://www.php.net/manual/en/function.min.php
    $scaleX = $targetLogoWidth / max(1, $logoOrigWidth);
    $scaleY = $targetLogoHeight / max(1, $logoOrigHeight);
    $scale  = min($scaleX, $scaleY);

    $scaledLogoWidth  = max(1, (int) round($logoOrigWidth * $scale));
    $scaledLogoHeight = max(1, (int) round($logoOrigHeight * $scale));

    // ==================================================================
    // 📍 Calculate centering position
    // The logo is placed at the exact center of the QR code.
    // ==================================================================
    $logoX = (int) round(($qrWidth - $scaledLogoWidth) / 2);
    $logoY = (int) round(($qrHeight - $scaledLogoHeight) / 2);

    // ==================================================================
    // ⬜ Draw padding/background behind the logo (if enabled)
    // This creates a white area behind the logo for better visibility.
    // @see https://www.php.net/manual/en/function.imagefilledellipse.php
    // @see https://www.php.net/manual/en/function.imagefilledrectangle.php
    // ==================================================================
    if ($addPadding) {
        $paddingX = (int) round($scaledLogoWidth * ($paddingPct / 100));
        $paddingY = (int) round($scaledLogoHeight * ($paddingPct / 100));

        // 📌 Allocate white colour for the padding background
        // @see https://www.php.net/manual/en/function.imagecolorallocate.php
        $white = imagecolorallocate($qrImage, 255, 255, 255);

        if ($paddingShape === 'circle') {
            // ⭕ Circular padding behind logo
            // @see https://www.php.net/manual/en/function.imagefilledellipse.php
            $ellipseWidth  = $scaledLogoWidth + ($paddingX * 2);
            $ellipseHeight = $scaledLogoHeight + ($paddingY * 2);
            $centerX       = $logoX + (int) round($scaledLogoWidth / 2);
            $centerY       = $logoY + (int) round($scaledLogoHeight / 2);

            imagefilledellipse(
                $qrImage,
                $centerX,
                $centerY,
                $ellipseWidth,
                $ellipseHeight,
                $white
            );
        } elseif ($paddingShape === 'rounded') {
            // 🔲 Rounded rectangle padding behind logo
            // GD doesn't have a native rounded rect function, so we draw
            // a rectangle and circles at the corners.
            $padLeft   = $logoX - $paddingX;
            $padTop    = $logoY - $paddingY;
            $padRight  = $logoX + $scaledLogoWidth + $paddingX;
            $padBottom = $logoY + $scaledLogoHeight + $paddingY;
            $radius    = min($paddingX, $paddingY);

            // 📌 Draw the main rectangle
            // @see https://www.php.net/manual/en/function.imagefilledrectangle.php
            imagefilledrectangle($qrImage, $padLeft + $radius, $padTop, $padRight - $radius, $padBottom, $white);
            imagefilledrectangle($qrImage, $padLeft, $padTop + $radius, $padRight, $padBottom - $radius, $white);

            // 📌 Draw corner circles for rounded effect
            // @see https://www.php.net/manual/en/function.imagefilledellipse.php
            imagefilledellipse($qrImage, $padLeft + $radius, $padTop + $radius, $radius * 2, $radius * 2, $white);
            imagefilledellipse($qrImage, $padRight - $radius, $padTop + $radius, $radius * 2, $radius * 2, $white);
            imagefilledellipse($qrImage, $padLeft + $radius, $padBottom - $radius, $radius * 2, $radius * 2, $white);
            imagefilledellipse($qrImage, $padRight - $radius, $padBottom - $radius, $radius * 2, $radius * 2, $white);
        } else {
            // ⬜ Square padding behind logo
            // @see https://www.php.net/manual/en/function.imagefilledrectangle.php
            imagefilledrectangle(
                $qrImage,
                $logoX - $paddingX,
                $logoY - $paddingY,
                $logoX + $scaledLogoWidth + $paddingX,
                $logoY + $scaledLogoHeight + $paddingY,
                $white
            );
        }
    }

    // ==================================================================
    // 🖼️ Overlay the logo onto the QR code
    // Uses imagecopyresampled() for high-quality resampling with alpha
    // channel preservation.
    // @see https://www.php.net/manual/en/function.imagecopyresampled.php
    // ==================================================================
    imagecopyresampled(
        $qrImage,           // Destination image (QR code)
        $logoImage,          // Source image (logo)
        $logoX,              // Destination X (centered)
        $logoY,              // Destination Y (centered)
        0,                   // Source X (full logo)
        0,                   // Source Y (full logo)
        $scaledLogoWidth,    // Destination width (scaled)
        $scaledLogoHeight,   // Destination height (scaled)
        $logoOrigWidth,      // Source width (original)
        $logoOrigHeight      // Source height (original)
    );

    // ==================================================================
    // 📦 Render the final image to a binary string
    // ==================================================================
    $result = renderGdImageToString($qrImage, $outputFormat);

    // 🧹 Free memory
    // @see https://www.php.net/manual/en/function.imagedestroy.php
    imagedestroy($qrImage);
    imagedestroy($logoImage);

    return $result;
}

// ==============================================================================
// 📐 overlayLogoOnSVG() - Overlay logo on SVG QR code
// ==============================================================================

/**
 * 📐 Overlay a logo image onto an SVG QR code
 *
 * Parses the SVG content to find the viewBox dimensions, base64-encodes
 * the logo image, and inserts an <image> element centered within the SVG.
 *
 * @param string $svgContent The SVG markup string
 * @param string $logoPath   Absolute path to the logo image file
 * @param array  $config     Logo configuration:
 *   - 'size_pct'     (int) Logo size as percentage of SVG dimensions (default: 20)
 *
 * @return string The modified SVG markup with embedded logo
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/SVG/Element/image
 * @see https://www.php.net/manual/en/function.base64-encode.php
 * @see https://www.php.net/manual/en/function.preg-match.php
 *
 * @example
 *   $svgWithLogo = overlayLogoOnSVG(
 *       $svgContent,
 *       '/path/to/logo.png',
 *       ['size_pct' => 20]
 *   );
 */
function overlayLogoOnSVG(string $svgContent, string $logoPath, array $config = []): string
{
    // 📌 Extract configuration with defaults
    if (isset($config['size_pct'])) {
        $svgSizePctRaw = (int) $config['size_pct'];
    } else {
        $svgSizePctRaw = 20;
    }
    $sizePct = max(5, min(40, $svgSizePctRaw));

    // ==================================================================
    // 📐 Parse SVG viewBox to get dimensions
    // The viewBox attribute defines the SVG's coordinate system.
    // Format: viewBox="minX minY width height"
    // @see https://developer.mozilla.org/en-US/docs/Web/SVG/Attribute/viewBox
    // ==================================================================
    $svgWidth  = 0;
    $svgHeight = 0;

    // 📌 Try to extract from viewBox attribute
    // @see https://www.php.net/manual/en/function.preg-match.php
    if (preg_match('/viewBox\s*=\s*"([^"]+)"/', $svgContent, $viewBoxMatch) === 1) {
        $viewBoxParts = preg_split('/[\s,]+/', trim($viewBoxMatch[1]));
        if (count($viewBoxParts) >= 4) {
            $svgWidth  = (float) $viewBoxParts[2];
            $svgHeight = (float) $viewBoxParts[3];
        }
    }

    // 📌 Fallback: try width/height attributes
    if ($svgWidth <= 0 || $svgHeight <= 0) {
        if (preg_match('/\bwidth\s*=\s*"([0-9.]+)"/', $svgContent, $wMatch) === 1) {
            $svgWidth = (float) $wMatch[1];
        }
        if (preg_match('/\bheight\s*=\s*"([0-9.]+)"/', $svgContent, $hMatch) === 1) {
            $svgHeight = (float) $hMatch[1];
        }
    }

    // ❌ Cannot determine SVG dimensions
    if ($svgWidth <= 0 || $svgHeight <= 0) {
        if (function_exists('logError')) {
            logError(
                'warning',
                'Cannot determine SVG dimensions for logo overlay',
                'SVG content does not contain viewBox or width/height attributes.'
            );
        }
        return $svgContent;
    }

    // ==================================================================
    // 🖼️ Load and base64-encode the logo image
    // @see https://www.php.net/manual/en/function.file-get-contents.php
    // @see https://www.php.net/manual/en/function.base64-encode.php
    // ==================================================================
    $logoData = @file_get_contents($logoPath);

    if ($logoData === false) {
        if (function_exists('logError')) {
            logError('warning', 'Logo file not readable for SVG overlay', 'Path: ' . $logoPath);
        }
        return $svgContent;
    }

    // 📌 Detect the logo MIME type for the data URI
    // @see https://www.php.net/manual/en/function.mime-content-type.php
    $logoMime = 'image/png';
    if (function_exists('mime_content_type')) {
        $detectedMime = @mime_content_type($logoPath);
        if ($detectedMime !== false) {
            $logoMime = $detectedMime;
        }
    }

    // 📌 Base64-encode the logo data for embedding in SVG
    // @see https://www.php.net/manual/en/function.base64-encode.php
    $logoBase64 = base64_encode($logoData);
    $dataUri    = 'data:' . $logoMime . ';base64,' . $logoBase64;

    // ==================================================================
    // 📐 Calculate logo position and size within the SVG
    // ==================================================================
    $logoWidth  = $svgWidth * ($sizePct / 100);
    $logoHeight = $svgHeight * ($sizePct / 100);
    $logoX      = ($svgWidth - $logoWidth) / 2;
    $logoY      = ($svgHeight - $logoHeight) / 2;

    // ==================================================================
    // 📋 Build the SVG <image> element
    // @see https://developer.mozilla.org/en-US/docs/Web/SVG/Element/image
    // ==================================================================
    $imageElement = '  <image'
        . ' x="' . round($logoX, 2) . '"'
        . ' y="' . round($logoY, 2) . '"'
        . ' width="' . round($logoWidth, 2) . '"'
        . ' height="' . round($logoHeight, 2) . '"'
        . ' href="' . htmlspecialchars($dataUri, ENT_QUOTES, 'UTF-8') . '"'
        . ' preserveAspectRatio="xMidYMid meet"'
        . '/>' . PHP_EOL;

    // ==================================================================
    // 📌 Insert the <image> element before the closing </svg> tag
    // @see https://www.php.net/manual/en/function.str-replace.php
    // ==================================================================
    $modifiedSvg = str_replace('</svg>', $imageElement . '</svg>', $svgContent);

    return $modifiedSvg;
}

// ==============================================================================
// 📂 getLogoPath() - Resolve a logo input to an absolute file path
// ==============================================================================

/**
 * 📂 Resolve a logo input to an absolute file path on the server
 *
 * Handles three types of logo inputs:
 *   1. Numeric ID: looks up a predefined logo in tblPredefinedLogos
 *   2. Base64 data URI: decodes and saves to a temporary file
 *   3. File path string: validates that the file exists
 *
 * @param mixed $logoInput The logo identifier (int ID, string base64, or string path)
 *
 * @return string|null The resolved absolute file path, or null if unresolvable
 *
 * @see https://www.php.net/manual/en/function.is-numeric.php
 * @see https://www.php.net/manual/en/function.base64-decode.php
 * @see https://www.php.net/manual/en/function.tempnam.php
 *
 * @example
 *   // Predefined logo by ID
 *   $path = getLogoPath(5);
 *   // Returns: '/var/www/CueRCode/_uploads/logos/predefined/logo-5.png'
 *
 *   // Base64 upload
 *   $path = getLogoPath('data:image/png;base64,iVBOR...');
 *   // Returns: '/tmp/cuercode_logo_xyz123.png'
 *
 *   // Direct file path
 *   $path = getLogoPath('/var/www/uploads/my-logo.png');
 *   // Returns: '/var/www/uploads/my-logo.png'
 */
function getLogoPath(mixed $logoInput): ?string
{
    // ❌ Handle null/empty input
    if ($logoInput === null || $logoInput === '' || $logoInput === false) {
        return null;
    }

    // ==================================================================
    // 🔢 Case 1: Numeric ID - look up predefined logo from database
    // @see https://www.php.net/manual/en/function.is-numeric.php
    // ==================================================================
    if (is_numeric($logoInput)) {
        return getLogoPathFromDatabase((int) $logoInput);
    }

    // 📌 Ensure input is a string for remaining checks
    if (!is_string($logoInput)) {
        return null;
    }

    // ==================================================================
    // 📦 Case 2: Base64 data URI - decode and save to temporary file
    // Format: data:image/{type};base64,{encoded_data}
    // @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Basics_of_HTTP/Data_URIs
    // ==================================================================
    if (strpos($logoInput, 'data:') === 0) {
        return saveBase64LogoToTemp($logoInput);
    }

    // ==================================================================
    // 📁 Case 3: File path - validate existence WITH base-directory containment
    //
    // 🔒 SECURITY (F-017): the 'logo' value here is REQUEST-supplied
    //    (customization.logo flows straight from the API/form into this
    //    function — see buildCustomizationFromRequest()). A bare realpath()
    //    with no base check would happily resolve an attacker-controlled
    //    absolute or traversal path (e.g. '/etc/passwd', '../../config.php')
    //    and hand it to the GD/SVG overlay, leaking file contents (bounded to
    //    image-parseable files by GD, but still a least-privilege defect).
    //
    //    Containment: the resolved real path MUST live inside the allowed
    //    uploads/logos directory. We mirror the traversal guard used by the
    //    download handler (fn_api_download.php): realpath() the candidate,
    //    realpath() the base, and reject anything that does not begin with
    //    "<base>/". The system temp dir is ALSO allowed so that base64 logo
    //    temp files (written by saveBase64LogoToTemp() under sys_get_temp_dir())
    //    remain usable if their path is ever round-tripped through Case 3.
    // @see https://www.php.net/manual/en/function.realpath.php
    // @see https://www.php.net/manual/en/function.file-exists.php
    // @see fn_api_download.php - identical realpath-under-base containment guard
    // ==================================================================
    if (is_string($logoInput) && strlen($logoInput) > 0) {
        // 📌 Build the set of allowed base directories the logo MUST resolve
        //    under. Each entry is realpath()-resolved so symlinks in the base
        //    itself are normalised before comparison.
        $allowedBases = [];

        // 🗂️ Primary allowed base: the uploads/logos directory.
        if (defined('CUERCODE_UPLOADS_DIR')) {
            $logosBase = realpath(CUERCODE_UPLOADS_DIR . 'logos');
            if ($logosBase !== false) {
                $allowedBases[] = $logosBase;
            }
        }

        // 🌡️ Secondary allowed base: the system temp dir (base64 temp logos).
        //    saveBase64LogoToTemp() writes decoded uploads here; allowing it
        //    keeps that legitimate path working without widening exposure to
        //    arbitrary server locations.
        $tempBase = realpath(sys_get_temp_dir());
        if ($tempBase !== false) {
            $allowedBases[] = $tempBase;
        }

        // 📌 Candidate 1: the raw input, resolved as-is. (An absolute path or a
        //    path relative to CWD; only accepted if it lands inside a base.)
        // 📌 Candidate 2: the input resolved relative to uploads/logos, which
        //    is how predefined logo FILENAMES (e.g. 'brand-mark.png') resolve.
        $candidates = [];

        $rawReal = realpath($logoInput);
        if ($rawReal !== false) {
            $candidates[] = $rawReal;
        }

        if (defined('CUERCODE_UPLOADS_DIR')) {
            $uploadsReal = realpath(
                CUERCODE_UPLOADS_DIR . 'logos' . DIRECTORY_SEPARATOR . $logoInput
            );
            if ($uploadsReal !== false) {
                $candidates[] = $uploadsReal;
            }
        }

        // 🔒 Accept the FIRST candidate that (a) is a real existing file and
        //    (b) is contained within one of the allowed base directories.
        foreach ($candidates as $candidatePath) {
            if (!file_exists($candidatePath) || !is_file($candidatePath)) {
                continue;
            }

            if (isPathWithinAllowedBase($candidatePath, $allowedBases)) {
                return $candidatePath;
            }
        }

        // ❌ A non-empty path was supplied but resolved OUTSIDE every allowed
        //    base (or did not exist). Treat this as a blocked traversal /
        //    containment failure and fall through to the null return + log.
        if (function_exists('logError')) {
            logError(
                'error',
                'Logo file-path containment check failed (F-017 guard)',
                'Input: ' . mb_substr($logoInput, 0, 200)
            );
        }
    }

    // ❌ Could not resolve logo path
    if (function_exists('logError')) {
        if (is_string($logoInput)) {
            $logoInputDisplay = mb_substr($logoInput, 0, 100);
        } else {
            $logoInputDisplay = 'non-string';
        }
        logError(
            'warning',
            'Logo path could not be resolved',
            'Input: ' . $logoInputDisplay
        );
    }

    return null;
}

// ==============================================================================
// 🔒 isPathWithinAllowedBase() - Base-directory containment check (F-017)
// ==============================================================================

/**
 * 🔒 Determine whether an ALREADY-RESOLVED real path sits inside one of the
 *    allowed base directories.
 *
 * This is the containment primitive behind the F-017 logo-path guard. It does
 * NOT resolve the candidate itself — callers must pass a realpath()-resolved,
 * existing path (so symlinks/.. are already normalised). Each base is compared
 * with a trailing directory separator appended, so that a sibling directory
 * sharing a name PREFIX (e.g. '/uploads/logos-evil' vs base '/uploads/logos')
 * cannot satisfy the check. An exact match of the base directory itself is not
 * accepted (we only allow files strictly *under* a base).
 *
 * @param string   $resolvedPath  A realpath()-resolved, existing file path.
 * @param string[] $allowedBases  realpath()-resolved base directories.
 *
 * @return bool True if $resolvedPath is contained within any allowed base.
 *
 * @see https://www.php.net/manual/en/function.str-starts-with.php
 * @see fn_api_download.php - the original strpos()-based traversal guard
 */
function isPathWithinAllowedBase(string $resolvedPath, array $allowedBases): bool
{
    foreach ($allowedBases as $base) {
        // 📌 Skip empty/false bases (a base whose realpath() failed).
        if (!is_string($base) || $base === '') {
            continue;
        }

        // 📌 Anchor the comparison to "<base>/" so prefix-collision siblings
        //    are rejected and only true descendants match.
        // @see https://www.php.net/manual/en/function.str-starts-with.php
        $prefix = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if (str_starts_with($resolvedPath, $prefix)) {
            return true;
        }
    }

    return false;
}

// ==============================================================================
// 🗄️ getLogoPathFromDatabase() - Look up predefined logo by ID
// ==============================================================================

/**
 * 🗄️ Look up a predefined logo file path from the database
 *
 * Queries tblPredefinedLogos for the specified logo ID and returns
 * the absolute file path if the logo exists and its file is accessible.
 *
 * @param int $logoId The predefined logo ID from tblPredefinedLogos
 *
 * @return string|null Absolute file path, or null if not found
 *
 * @see https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php
 */
function getLogoPathFromDatabase(int $logoId): ?string
{
    // ✅ Verify database function is available
    if (!function_exists('dbQuery')) {
        return null;
    }

    try {
        // 📌 NOTE: tblPredefinedLogos stores only the filename (logoFileName);
        //     there is no stored absolute-path column. The path is constructed
        //     at runtime from the predefined logos directory + logoFileName.
        $stmt = dbQuery(
            "SELECT `logoFileName` "
            . "FROM `tblPredefinedLogos` "
            . "WHERE `logoID` = ? "
            . "AND `isActive` = 1 "
            . "LIMIT 1",
            'i',
            [$logoId]
        );

        $result = $stmt->get_result();
        $row    = $result->fetch_assoc();
        $stmt->close();

        if ($row === null) {
            return null;
        }

        // 📌 Build the absolute path from the stored filename. The schema only
        //     stores logoFileName; the directory is resolved at runtime.
        if (isset($row['logoFileName'])) {
            $logoFileName = $row['logoFileName'];
        } else {
            $logoFileName = '';
        }

        if ($logoFileName === '') {
            return null;
        }

        // 📌 Try relative to the uploads/logos directory.
        // 🔒 B-051 (defence-in-depth): even though logoFileName comes from a DB
        //    row (admin-controlled, not request-supplied), an admin could set a
        //    malformed value containing traversal segments ('../../config.php')
        //    or an absolute path. Run the resolved path through the SAME
        //    realpath-under-base containment guard added in cycle 5 for the
        //    request-supplied logo path (Case 3 of getLogoPath()), so a stored
        //    value can never escape the uploads/logos base directory either.
        // @see getLogoPath() - Case 3 containment guard (F-017)
        // @see isPathWithinAllowedBase()
        if (defined('CUERCODE_UPLOADS_DIR')) {
            $logosBaseDir = CUERCODE_UPLOADS_DIR . 'logos';

            // 📌 Resolve the allowed base once (normalises symlinks/.. in the base).
            $allowedBase = realpath($logosBaseDir);
            if ($allowedBase === false) {
                // ⚠️ Base directory missing/unresolvable — cannot contain anything.
                return null;
            }

            // 📌 Resolve the candidate file (uploads/logos + stored filename).
            $fullPath     = $logosBaseDir . DIRECTORY_SEPARATOR . $logoFileName;
            $resolvedReal = realpath($fullPath);

            if ($resolvedReal !== false
                && file_exists($resolvedReal)
                && is_file($resolvedReal)
                && isPathWithinAllowedBase($resolvedReal, [$allowedBase])
            ) {
                return $resolvedReal;
            }

            // ❌ Stored filename resolved outside the base (or did not exist):
            //    block it and log, rather than returning an escaping path.
            if (function_exists('logError')) {
                logError(
                    'error',
                    'Predefined logo path containment check failed (B-051 guard)',
                    'Logo ID: ' . $logoId . ' | Stored filename: '
                    . mb_substr((string) $logoFileName, 0, 200)
                );
            }
        }

        return null;
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError(
                'warning',
                'Predefined logo lookup failed',
                'Logo ID: ' . $logoId . ' | ' . $e->getMessage()
            );
        }
        return null;
    }
}

// ==============================================================================
// 📦 saveBase64LogoToTemp() - Decode base64 data URI and save to temp file
// ==============================================================================

/**
 * 📦 Decode a base64 data URI and save it to a temporary file
 *
 * Parses the data URI format, decodes the base64 content, and writes
 * it to a temporary file that can be used as a logo source.
 *
 * @param string $dataUri The base64 data URI (e.g., 'data:image/png;base64,...')
 *
 * @return string|null Path to the temporary file, or null on failure
 *
 * @see https://www.php.net/manual/en/function.base64-decode.php
 * @see https://www.php.net/manual/en/function.tempnam.php
 * @see https://www.php.net/manual/en/function.file-put-contents.php
 */
function saveBase64LogoToTemp(string $dataUri): ?string
{
    // 📌 Parse the data URI format: data:{mime};base64,{data}
    // @see https://www.php.net/manual/en/function.preg-match.php
    if (preg_match('/^data:image\/([a-zA-Z0-9+.-]+);base64,(.+)$/s', $dataUri, $matches) !== 1) {
        if (function_exists('logError')) {
            logError('warning', 'Invalid base64 data URI format for logo', '');
        }
        return null;
    }

    $imageType  = strtolower($matches[1]);
    $base64Data = $matches[2];

    // 📌 Map MIME sub-type to file extension
    $extensionMap = [
        'png'     => 'png',
        'jpeg'    => 'jpg',
        'jpg'     => 'jpg',
        'gif'     => 'gif',
        'webp'    => 'webp',
        'svg+xml' => 'svg',
    ];

    if (isset($extensionMap[$imageType])) {
        $extension = $extensionMap[$imageType];
    } else {
        $extension = 'png';
    }

    // 📌 Decode the base64 data
    // @see https://www.php.net/manual/en/function.base64-decode.php
    $imageData = base64_decode($base64Data, true);

    if ($imageData === false) {
        if (function_exists('logError')) {
            logError('warning', 'Failed to decode base64 logo data', '');
        }
        return null;
    }

    // 🛡️ F-005: enforce the decoded-byte cap BEFORE touching the disk or GD.
    //    A small base64 payload can decode to a large blob; rejecting here
    //    (in-memory, pre-write) avoids spending I/O on an over-size logo.
    // @see CUERCODE_LOGO_MAX_DECODED_BYTES (defined at the top of this file)
    if (strlen($imageData) > CUERCODE_LOGO_MAX_DECODED_BYTES) {
        if (function_exists('logError')) {
            logError(
                'warning',
                'Base64 logo rejected: decoded size exceeds limit (F-005)',
                'Decoded bytes: ' . strlen($imageData)
                . ' | Limit: ' . CUERCODE_LOGO_MAX_DECODED_BYTES
            );
        }
        return null;
    }

    // 📌 Create a temporary file with the appropriate extension
    // @see https://www.php.net/manual/en/function.tempnam.php
    // @see https://www.php.net/manual/en/function.sys-get-temp-dir.php
    $tempDir  = sys_get_temp_dir();
    $tempFile = tempnam($tempDir, 'cuercode_logo_');

    if ($tempFile === false) {
        if (function_exists('logError')) {
            logError('error', 'Failed to create temporary file for logo', '');
        }
        return null;
    }

    // 📌 Rename with the correct extension
    $tempFileWithExt = $tempFile . '.' . $extension;
    rename($tempFile, $tempFileWithExt);

    // 📌 Write the decoded image data to the temp file
    // @see https://www.php.net/manual/en/function.file-put-contents.php
    $bytesWritten = file_put_contents($tempFileWithExt, $imageData);

    if ($bytesWritten === false) {
        // 🧹 Clean up the empty temp file
        @unlink($tempFileWithExt);

        if (function_exists('logError')) {
            logError('error', 'Failed to write logo data to temporary file', 'Path: ' . $tempFileWithExt);
        }
        return null;
    }

    // 🛡️ F-005: enforce the pixel-dimension cap BEFORE the image is handed to
    //    GD (loadLogoImage / overlayLogoOnRaster). getimagesize() reads only the
    //    header to learn declared width/height, so a tiny file claiming enormous
    //    dimensions (a "pixel bomb") is caught here without GD ever allocating a
    //    multi-gigabyte canvas. SVG is XML-based and not measurable by
    //    getimagesize() (it returns false), so the pixel check is skipped for
    //    SVG — its size is already bounded by the decoded-byte cap above.
    // @see https://www.php.net/manual/en/function.getimagesize.php
    // @see CUERCODE_LOGO_MAX_WIDTH / CUERCODE_LOGO_MAX_HEIGHT (top of this file)
    if ($extension !== 'svg') {
        $dimensions = @getimagesize($tempFileWithExt);

        if ($dimensions === false) {
            // ⚠️ Not a parseable raster image (corrupt or spoofed). Reject and
            //    clean up rather than let GD attempt to load garbage.
            @unlink($tempFileWithExt);

            if (function_exists('logError')) {
                logError(
                    'warning',
                    'Base64 logo rejected: not a valid image (getimagesize failed) (F-005)',
                    'Path: ' . $tempFileWithExt
                );
            }
            return null;
        }

        $logoWidth  = (int) $dimensions[0];
        $logoHeight = (int) $dimensions[1];

        if ($logoWidth > CUERCODE_LOGO_MAX_WIDTH
            || $logoHeight > CUERCODE_LOGO_MAX_HEIGHT
        ) {
            // 🧹 Over-size pixel dimensions — discard the temp file and reject.
            @unlink($tempFileWithExt);

            if (function_exists('logError')) {
                logError(
                    'warning',
                    'Base64 logo rejected: pixel dimensions exceed limit (F-005)',
                    'Dimensions: ' . $logoWidth . 'x' . $logoHeight
                    . ' | Limit: ' . CUERCODE_LOGO_MAX_WIDTH . 'x' . CUERCODE_LOGO_MAX_HEIGHT
                );
            }
            return null;
        }
    }

    return $tempFileWithExt;
}

// ==============================================================================
// 📷 loadLogoImage() - Load a logo image file into a GD resource
// ==============================================================================

/**
 * 📷 Load a logo image file into a GD image resource
 *
 * Detects the image type and uses the appropriate GD function to load it.
 * Supports PNG, JPEG, GIF, WEBP, and SVG (rasterised via external tool if available).
 *
 * For all loaded images, alpha blending and save-alpha are configured
 * for proper transparency compositing.
 *
 * @param string $logoPath Absolute path to the logo image file
 *
 * @return \GdImage|null The loaded GD image resource, or null on failure
 *
 * @see https://www.php.net/manual/en/function.imagecreatefrompng.php
 * @see https://www.php.net/manual/en/function.imagecreatefromjpeg.php
 * @see https://www.php.net/manual/en/function.imagecreatefromgif.php
 * @see https://www.php.net/manual/en/function.imagecreatefromwebp.php
 * @see https://www.php.net/manual/en/function.exif-imagetype.php
 */
function loadLogoImage(string $logoPath): ?\GdImage
{
    // ❌ Verify the file exists
    if (!file_exists($logoPath) || !is_file($logoPath)) {
        return null;
    }

    // 📌 Detect the image type using exif_imagetype() or mime_content_type()
    // @see https://www.php.net/manual/en/function.exif-imagetype.php
    $imageType = null;

    if (function_exists('exif_imagetype')) {
        $imageType = @exif_imagetype($logoPath);
    }

    // 📌 Fallback: use mime_content_type
    if ($imageType === false || $imageType === null) {
        $mimeType = '';
        if (function_exists('mime_content_type')) {
            $mimeType = @mime_content_type($logoPath);
        }

        // 📌 Map MIME type to IMAGETYPE_* constant
        $mimeMap = [
            'image/png'  => IMAGETYPE_PNG,
            'image/jpeg' => IMAGETYPE_JPEG,
            'image/gif'  => IMAGETYPE_GIF,
            'image/webp' => IMAGETYPE_WEBP,
        ];

        if (isset($mimeMap[$mimeType])) {
            $imageType = $mimeMap[$mimeType];
        } else {
            $imageType = null;
        }
    }

    // 📌 Load the image using the appropriate GD function
    $logoImage = null;

    try {
        if ($imageType === IMAGETYPE_PNG) {
            // @see https://www.php.net/manual/en/function.imagecreatefrompng.php
            $logoImage = @imagecreatefrompng($logoPath);
        } elseif ($imageType === IMAGETYPE_JPEG) {
            // @see https://www.php.net/manual/en/function.imagecreatefromjpeg.php
            $logoImage = @imagecreatefromjpeg($logoPath);
        } elseif ($imageType === IMAGETYPE_GIF) {
            // @see https://www.php.net/manual/en/function.imagecreatefromgif.php
            $logoImage = @imagecreatefromgif($logoPath);
        } elseif ($imageType === IMAGETYPE_WEBP) {
            // @see https://www.php.net/manual/en/function.imagecreatefromwebp.php
            $logoImage = @imagecreatefromwebp($logoPath);
        } else {
            // 📌 Fallback: try imagecreatefromstring() for unknown types
            // @see https://www.php.net/manual/en/function.imagecreatefromstring.php
            $fileData  = @file_get_contents($logoPath);
            if ($fileData !== false) {
                $logoImage = @imagecreatefromstring($fileData);
            }
        }
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError(
                'warning',
                'Failed to load logo image via GD',
                'Path: ' . $logoPath . ' | ' . $e->getMessage()
            );
        }
        return null;
    }

    if ($logoImage === false || $logoImage === null) {
        return null;
    }

    // 📌 Configure alpha blending for proper transparency compositing
    // @see https://www.php.net/manual/en/function.imagealphablending.php
    imagealphablending($logoImage, true);
    // @see https://www.php.net/manual/en/function.imagesavealpha.php
    imagesavealpha($logoImage, true);

    return $logoImage;
}

// ==============================================================================
// 📦 renderGdImageToString() - Render a GD image to a binary string
// ==============================================================================

/**
 * 📦 Render a GD image resource to a binary string in the specified format
 *
 * Uses output buffering to capture the image output function's result
 * as a string instead of writing to stdout.
 *
 * @param \GdImage $image  The GD image resource to render
 * @param string   $format The output format: 'png', 'jpg', 'gif', 'webp'
 *
 * @return string The image data as a binary string
 *
 * @see https://www.php.net/manual/en/function.ob-start.php
 * @see https://www.php.net/manual/en/function.ob-get-clean.php
 * @see https://www.php.net/manual/en/function.imagepng.php
 * @see https://www.php.net/manual/en/function.imagejpeg.php
 *
 * @example
 *   $pngData = renderGdImageToString($image, 'png');
 *   $jpgData = renderGdImageToString($image, 'jpg');
 */
function renderGdImageToString(\GdImage $image, string $format = 'png'): string
{
    // 📌 Start output buffering to capture image output
    // @see https://www.php.net/manual/en/function.ob-start.php
    ob_start();

    // 📌 Render the image in the specified format
    if ($format === 'jpg' || $format === 'jpeg') {
        // @see https://www.php.net/manual/en/function.imagejpeg.php
        imagejpeg($image, null, 90);
    } elseif ($format === 'gif') {
        // @see https://www.php.net/manual/en/function.imagegif.php
        imagegif($image);
    } elseif ($format === 'webp') {
        // @see https://www.php.net/manual/en/function.imagewebp.php
        imagewebp($image, null, 90);
    } else {
        // 📌 Default: PNG with maximum compression
        // @see https://www.php.net/manual/en/function.imagepng.php
        imagepng($image, null, 9);
    }

    // 📌 Capture the buffered output and return it
    // @see https://www.php.net/manual/en/function.ob-get-clean.php
    $output = ob_get_clean();

    if ($output !== false) {
        return $output;
    } else {
        return '';
    }
}
