<?php
/**
 * ==============================================================================
 * 🖼️ CueRCode - Logo Overlay for QR Codes
 * ==============================================================================
 *
 * Path: _functions/fn_qr_logo.php
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
 * @copyright   2025–2026 MWBM Partners Ltd
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
// 🗂️ resolvePredefinedLogoPath() - Resolve a predefined logo filename to a
//     contained, absolute file path (Issue #65 fix)
// ==============================================================================

/**
 * 🗂️ Resolve a predefined logo's stored filename to an absolute, base-contained
 *    file path
 *
 * Extracted out of getLogoPathFromDatabase() as its own small, pure(ish)
 * function so the CORE "which directory actually holds predefined logo
 * files, and how do we safely resolve a stored filename into it" logic is
 * independently unit-testable WITHOUT a database connection (see
 * tests/test_qr_logo.php - this is the function that proves Issue #65's fix,
 * since getLogoPathFromDatabase() itself needs a live/mocked dbQuery() the
 * DB-independent test harness deliberately never provides).
 *
 * 🐛 ISSUE #65 (predefined centre-logos are dead at runtime): predefined
 *    logos (tblPredefinedLogos, seeded by _includes/sql/seed_data.sql, see
 *    the comment directly above that INSERT: "These logos correspond to
 *    SVG/PNG files in public_html/assets/img/logos/") ship as STATIC SITE
 *    ASSETS under public_html/assets/img/logos/ - they are NOT user uploads
 *    and were NEVER written under the uploads/logos tree. Before this fix,
 *    ONLY CUERCODE_UPLOADS_DIR . 'logos' was checked, so EVERY predefined
 *    logo lookup resolved to a path outside the (only) allowed base and was
 *    silently rejected by the containment guard -> getLogoPathFromDatabase()
 *    always returned null -> the logo was silently dropped from every QR
 *    code that referenced one, with no visible error to the end user.
 *
 *    Fix: the real assets/img/logos directory is now tried FIRST (that is
 *    where every predefined logo actually lives), then uploads/logos as a
 *    secondary base (kept for defence-in-depth / forward-compatibility, in
 *    case a future predefined entry is ever placed there instead).
 *
 * 🔒 SECURITY - the B-051/F-017 containment guard is preserved UNCHANGED and
 *    applied to BOTH candidate bases: every candidate is realpath()-resolved
 *    and MUST land inside one of the allowed base directories
 *    (isPathWithinAllowedBase()) before being returned. logoFileName is
 *    admin-curated (tblPredefinedLogos is not writable via any public API),
 *    not request-supplied, but a malformed stored value (e.g. containing
 *    '../../' traversal segments) still cannot escape either base - this
 *    function does not weaken that guard in any way, it only widens WHERE
 *    (which base directories) a legitimately-contained file may be found.
 *
 * @param string $logoFileName The stored `logoFileName` value from
 *                              tblPredefinedLogos (e.g. 'facebook.svg').
 *
 * @return string|null Absolute, base-contained file path, or null if the
 *                      filename is empty, does not exist under either
 *                      allowed base, or resolves outside containment.
 *
 * @see getLogoPathFromDatabase() - the DB-lookup caller of this function
 * @see isPathWithinAllowedBase() - the shared realpath-under-base primitive
 * @see https://www.php.net/manual/en/function.realpath.php
 *
 * @example
 *   $path = resolvePredefinedLogoPath('facebook.svg');
 *   // Returns: '/.../web/CueRCode.net/public_html/assets/img/logos/facebook.svg'
 */
function resolvePredefinedLogoPath(string $logoFileName): ?string
{
    // ❌ Nothing to resolve.
    if ($logoFileName === '') {
        return null;
    }

    // 📌 Ordered list of candidate base directories to try, FIRST match wins.
    //    Each entry is the UN-resolved base path; it is realpath()-resolved
    //    per-candidate below so a missing directory on a given deployment is
    //    simply skipped rather than raising an error.
    $candidateBaseDirs = [];

    // 🖼️ Issue #65 fix: the REAL home of predefined logo files.
    if (defined('CUERCODE_SITE_ROOT')) {
        $candidateBaseDirs[] = CUERCODE_SITE_ROOT
            . 'public_html' . DIRECTORY_SEPARATOR
            . 'assets' . DIRECTORY_SEPARATOR
            . 'img' . DIRECTORY_SEPARATOR
            . 'logos';
    }

    // 🗂️ Secondary/legacy base: the user-uploads logos directory.
    if (defined('CUERCODE_UPLOADS_DIR')) {
        $candidateBaseDirs[] = CUERCODE_UPLOADS_DIR . 'logos';
    }

    // 🔎 Try each candidate base in order; return the FIRST resolved,
    //    existing, base-contained file.
    foreach ($candidateBaseDirs as $baseDir) {
        // 📌 Resolve the allowed base once (normalises symlinks/.. in the
        //    base itself). A base that does not exist on this deployment
        //    (realpath() returns false) is simply skipped, not fatal.
        $allowedBase = realpath($baseDir);

        if ($allowedBase === false) {
            continue;
        }

        // 📌 Resolve the candidate file (base directory + stored filename).
        $fullPath     = $baseDir . DIRECTORY_SEPARATOR . $logoFileName;
        $resolvedReal = realpath($fullPath);

        if ($resolvedReal !== false
            && file_exists($resolvedReal)
            && is_file($resolvedReal)
            && isPathWithinAllowedBase($resolvedReal, [$allowedBase])
        ) {
            return $resolvedReal;
        }
    }

    // ❌ The stored filename did not resolve to an existing, contained file
    //    under ANY candidate base.
    return null;
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
 * @see resolvePredefinedLogoPath() - the (Issue #65-fixed) resolution logic
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

        // 📌 Resolve the stored filename against the allowed predefined-logo
        //    base directories (assets/img/logos FIRST, then uploads/logos as
        //    a secondary/legacy base). See resolvePredefinedLogoPath()'s own
        //    doc block for the full Issue #65 fix rationale.
        //
        // 🔒 B-051 (defence-in-depth): even though logoFileName comes from a DB
        //    row (admin-controlled, not request-supplied), an admin could set a
        //    malformed value containing traversal segments ('../../config.php')
        //    or an absolute path. resolvePredefinedLogoPath() runs the SAME
        //    realpath-under-base containment guard added in cycle 5 for the
        //    request-supplied logo path (Case 3 of getLogoPath()) against EVERY
        //    candidate base, so a stored value can never escape either
        //    directory.
        // @see resolvePredefinedLogoPath() - the extracted, unit-tested resolver
        // @see getLogoPath() - Case 3 containment guard (F-017)
        // @see isPathWithinAllowedBase()
        $resolvedPath = resolvePredefinedLogoPath($logoFileName);

        if ($resolvedPath !== null) {
            return $resolvedPath;
        }

        // ❌ Stored filename resolved outside every allowed base (or did not
        //    exist under any of them): block it and log, rather than
        //    returning an escaping path or failing silently.
        if (function_exists('logError')) {
            logError(
                'error',
                'Predefined logo path containment check failed (B-051 guard / #65)',
                'Logo ID: ' . $logoId . ' | Stored filename: '
                . mb_substr((string) $logoFileName, 0, 200)
            );
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

    // 🛡️ F-024 (#52): screen SVG content for active/script vectors using the
    //    SAME shared screener the multipart $_FILES upload path uses
    //    (screenSvgLogoContent(), fn_validation.php). BEFORE this fix, the
    //    base64 data-URI path enforced ONLY the F-005 size/pixel caps above,
    //    so an unscreened malicious SVG logo could reach the QR's own SVG
    //    output verbatim via overlayLogoOnSVG() (which embeds the RAW logo
    //    bytes as a nested <image href="data:image/svg+xml;base64,...">).
    //
    //    Runs whenever the declared extension is 'svg' (from the data URI's
    //    MIME sub-type) OR — defensively — whenever the decoded bytes sniff
    //    as SVG regardless of the declared MIME (a mislabelled sub-type must
    //    not bypass the screen). Deliberately placed BEFORE any disk I/O
    //    (tempnam()/file_put_contents() below) so a rejected payload never
    //    touches the filesystem at all.
    //
    //    Raster logos (PNG/JPG/GIF/WEBP) are entirely unaffected — this
    //    block is a no-op for them, and the existing F-005 byte/pixel caps
    //    above still apply to every format exactly as before.
    // @see screenSvgLogoContent() (fn_validation.php) - the shared screener
    // @see overlayLogoOnSVG() (this file) - where unscreened bytes would land
    // @see rejectUnsafeBase64SvgLogo() (this file) - the API-facing 422 pre-check
    $looksLikeSvg = ($extension === 'svg') || (stripos($imageData, '<svg') !== false);

    if ($looksLikeSvg) {
        if (function_exists('screenSvgLogoContent')) {
            $svgScreen = screenSvgLogoContent($imageData);

            if ($svgScreen['valid'] === false) {
                if (function_exists('logError')) {
                    logError(
                        'warning',
                        'Base64 logo rejected: unsafe SVG content (F-024)',
                        (string) $svgScreen['error']
                    );
                }
                return null;
            }
        } else {
            // ⚠️ fn_validation.php is not loaded, so the screener is
            //    unavailable. FAIL CLOSED for SVG rather than silently
            //    accepting unscreened active content — raster logos are
            //    unaffected by this else-branch entirely.
            if (function_exists('logError')) {
                logError(
                    'error',
                    'Base64 SVG logo rejected: screenSvgLogoContent() unavailable (fail-closed, F-024)',
                    ''
                );
            }
            return null;
        }
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
// 🔍 screenBase64LogoDataUri() - Pure pre-check: is this a malicious SVG? (F-024)
// ==============================================================================

/**
 * 🔍 Pre-screen a base64 logo data URI for malicious SVG content, WITHOUT
 *    writing anything to disk
 *
 * A pure, read-only companion to saveBase64LogoToTemp()'s in-line SVG screen
 * (above): it decodes the data URI in memory only and, if the content is SVG
 * (declared MIME `image/svg+xml` OR sniffed `<svg` content regardless of the
 * declared MIME), runs it through the SAME shared screenSvgLogoContent()
 * screener (fn_validation.php) used by both logo intake paths.
 *
 * This exists so the JSON API handlers (fn_api_generate.php, fn_api_qrcodes.php)
 * can reject a malicious SVG logo EARLY, with a distinct, actionable HTTP 422
 * `LOGO_SVG_REJECTED` error — rather than the request silently succeeding
 * with the logo quietly dropped (which is what happens further downstream if
 * saveBase64LogoToTemp() alone returns null: the generation pipeline's logo
 * overlay step treats a null logo path as "no logo" and continues).
 *
 * Non-SVG data URIs, and anything that is not a well-formed
 * `data:image/...;base64,...` string, are left alone here (returns null) —
 * saveBase64LogoToTemp() handles the normal decode-failure / size-cap paths
 * for those on its own.
 *
 * @param string $dataUri Candidate `data:image/...;base64,...` string (or any
 *                         other string — non-matching input returns null).
 *
 * @return string|null A human-readable rejection reason if unsafe SVG content
 *                      was found, or null if the input is safe / not an SVG
 *                      data URI / not decodable here.
 *
 * @see screenSvgLogoContent() (fn_validation.php) - the shared screener
 * @see saveBase64LogoToTemp() (this file) - the actual write-path enforcement
 * @see rejectUnsafeBase64SvgLogo() (this file) - the customization-shaped wrapper
 */
function screenBase64LogoDataUri(string $dataUri): ?string
{
    // 📌 Same data-URI shape saveBase64LogoToTemp() expects.
    if (preg_match('/^data:image\/([a-zA-Z0-9+.-]+);base64,(.+)$/s', trim($dataUri), $matches) !== 1) {
        return null;
    }

    $subtype = strtolower($matches[1]);

    // 📌 Decode in memory only - no filesystem I/O for this pre-check.
    // @see https://www.php.net/manual/en/function.base64-decode.php
    $decoded = base64_decode($matches[2], true);

    if ($decoded === false) {
        // ❌ Not decodable here; saveBase64LogoToTemp() rejects invalid base64
        //    itself when the real decode is attempted.
        return null;
    }

    // 📌 Treat as SVG if the declared MIME sub-type says so, OR the decoded
    //    bytes sniff as SVG regardless of what the MIME sub-type claims (a
    //    mislabelled sub-type must not bypass the screen).
    $looksLikeSvg = ($subtype === 'svg+xml') || (stripos($decoded, '<svg') !== false);

    if (!$looksLikeSvg) {
        return null;
    }

    if (!function_exists('screenSvgLogoContent')) {
        // ⚠️ fn_validation.php not loaded — fail closed for SVG rather than
        //    silently accept unscreened active content.
        return 'SVG safety screening is unavailable; SVG logo rejected as a precaution.';
    }

    $screen = screenSvgLogoContent($decoded);

    if ($screen['valid'] === false) {
        return (string) $screen['error'];
    }

    return null;
}

// ==============================================================================
// 🚫 rejectUnsafeBase64SvgLogo() - API-facing customization pre-check (F-024)
// ==============================================================================

/**
 * 🚫 Check a built customization array for a malicious base64 SVG logo
 *
 * Thin wrapper around screenBase64LogoDataUri() that knows the SHAPE of the
 * customization array produced by fn_api_generate.php's
 * buildCustomizationFromRequest(): a logo can arrive as EITHER
 * `customization['logo_data']` (the companion field sent alongside
 * `logo === 'upload'`) OR directly as `customization['logo']` (a client MAY
 * supply a `data:image/...;base64,...` string there directly — getLogoPath()
 * Case 2 accepts that shape too). Both are checked so neither entry point
 * can bypass the screen.
 *
 * Intended to be called by every JSON API handler that reaches
 * validateCustomization() — handleGenerate()/handlePreview()
 * (fn_api_generate.php) and the PATCH /qrcodes/{uuid} handler
 * (fn_api_qrcodes.php) — immediately BEFORE that call, so a malicious SVG
 * logo is rejected with a clear, distinct HTTP 422 `LOGO_SVG_REJECTED`
 * instead of falling through to a generic 400 `INVALID_CUSTOMIZATION` or
 * (worse) silently generating a logo-less QR code.
 *
 * @param array $customization The built customization array (post
 *                              buildCustomizationFromRequest()).
 *
 * @return string|null A ready-to-display rejection message, or null if no
 *                      unsafe SVG logo was found.
 *
 * @see screenBase64LogoDataUri() (this file) - the actual decode + screen
 * @see fn_api_generate.php - handleGenerate(), handlePreview()
 * @see fn_api_qrcodes.php  - the PATCH /qrcodes/{uuid} handler
 */
function rejectUnsafeBase64SvgLogo(array $customization): ?string
{
    $candidates = [];

    if (isset($customization['logo_data']) && is_string($customization['logo_data'])) {
        $candidates[] = $customization['logo_data'];
    }

    if (isset($customization['logo']) && is_string($customization['logo'])) {
        $candidates[] = $customization['logo'];
    }

    foreach ($candidates as $candidate) {
        if (!function_exists('screenBase64LogoDataUri')) {
            break;
        }

        $reason = screenBase64LogoDataUri($candidate);

        if ($reason !== null) {
            return 'Logo rejected: the SVG logo contains disallowed active content ('
                . $reason . ') Use a clean SVG or a raster image (PNG/JPG/GIF/WEBP) instead.';
        }
    }

    return null;
}

// ==============================================================================
// 📷 loadLogoImage() - Load a logo image file into a GD resource
// ==============================================================================

/**
 * 📷 Load a logo image file into a GD image resource
 *
 * Detects the image type and uses the appropriate GD function to load it.
 * Supports PNG, JPEG, GIF, and WEBP directly via GD. SVG is handled by the
 * dedicated SVG fallback chain below - GD itself has NO vector renderer and
 * cannot rasterise SVG under any circumstances.
 *
 * For all loaded images, alpha blending and save-alpha are configured
 * for proper transparency compositing.
 *
 * ------------------------------------------------------------------------
 * 🐛 ISSUE #65 - the "PNG twin" convention for raster SVG logo overlay
 * ------------------------------------------------------------------------
 * All 26 predefined logos (public_html/assets/img/logos/*.svg) are vector
 * SVG files. GD (imagecreatefromstring() and every imagecreatefromXXX()
 * sibling) can only decode RASTER formats - there is no SVG parser or
 * rasteriser anywhere inside GD - so handing GD an SVG file has always
 * silently failed here, and the overlay step (overlayLogoOnRaster(),
 * governed by $logoImage === null at its call site) silently dropped the
 * logo from every PNG/JPEG/GIF/WEBP QR code output. The SVG-output path
 * (overlayLogoOnSVG()) is UNAFFECTED - it embeds the raw SVG bytes as a
 * nested <image> element and never touches GD - and remains the fully
 * working path for predefined logos today.
 *
 * To make RASTER output work too, without a mandatory native-code
 * dependency, this function now tries, IN ORDER, whenever the resolved
 * logo path ends in '.svg':
 *
 *   1. A "PNG twin" file - a PNG with the SAME basename living in the SAME
 *      directory (e.g. 'facebook.svg' -> 'facebook.png'). This is the
 *      PREFERRED, ZERO-RUNTIME-DEPENDENCY mechanism: run the bundled CLI
 *      tool `tools/render_logo_pngs.php` ONCE, on any machine that has an
 *      SVG rasteriser (Imagick, rsvg-convert, Inkscape, etc. - this
 *      environment has none of those), to generate a PNG twin for every
 *      predefined logo. From then on raster QR output "just works" with no
 *      runtime SVG rasterisation cost or dependency at all.
 *   2. The PHP `imagick` extension, IF loaded at request time
 *      (extension_loaded('imagick')) - rasterises the SVG on the fly via
 *      Imagick's librsvg-backed SVG delegate. This is a graceful bonus for
 *      hosts that happen to have Imagick installed; it is NEVER required.
 *   3. Otherwise: this is a NON-SILENT failure. A clear, actionable warning
 *      is logged via logError() explaining exactly what is missing (a PNG
 *      twin or the imagick extension) and null is returned, so the caller
 *      (overlayLogoOnRaster()) falls back to returning the QR code
 *      completely unmodified - the QR code itself STAYS FULLY VALID and
 *      SCANNABLE, it simply renders without the logo overlay, and the
 *      reason is now discoverable in the error log instead of being an
 *      invisible no-op.
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
 * @see https://www.php.net/manual/en/book.imagick.php
 * @see tools/render_logo_pngs.php - the PNG-twin generator CLI tool
 * @see overlayLogoOnSVG() - the unaffected, already-working SVG-output path
 */
function loadLogoImage(string $logoPath): ?\GdImage
{
    // ❌ Verify the file exists
    if (!file_exists($logoPath) || !is_file($logoPath)) {
        return null;
    }

    // ==================================================================
    // 🖼️ SVG handling (Issue #65) - GD cannot rasterise SVG at all, so any
    //     '.svg' path is routed through the PNG-twin / Imagick fallback
    //     chain documented in this function's doc block ABOVE, rather than
    //     being handed to GD (which would just fail).
    //
    //     Detection is by FILE EXTENSION (not MIME sniffing): every logo
    //     path reaching this function has already been through either
    //     resolvePredefinedLogoPath() (predefined logos - filenames are
    //     admin-curated) or getLogoPath()'s Case 3 containment guard
    //     (uploaded logos), both of which resolve real, existing files by
    //     their actual on-disk name, so the extension is trustworthy here.
    // ==================================================================
    $pathExtension = strtolower((string) pathinfo($logoPath, PATHINFO_EXTENSION));

    if ($pathExtension === 'svg') {
        // ------------------------------------------------------------
        // 1️⃣ Fallback 1: a same-basename PNG twin in the SAME directory.
        // ------------------------------------------------------------
        $pngTwinPath = pathinfo($logoPath, PATHINFO_DIRNAME)
            . DIRECTORY_SEPARATOR
            . pathinfo($logoPath, PATHINFO_FILENAME)
            . '.png';

        if (file_exists($pngTwinPath) && is_file($pngTwinPath)) {
            // ✅ Re-enter this SAME function with the PNG twin's path so the
            //    normal PNG-detection/GD-load logic below handles it exactly
            //    like any other raster logo (single code path, no duplication).
            return loadLogoImage($pngTwinPath);
        }

        // ------------------------------------------------------------
        // 2️⃣ Fallback 2: rasterise on the fly via the Imagick extension,
        //    ONLY if it is actually loaded in this PHP build/config.
        // ------------------------------------------------------------
        if (extension_loaded('imagick')) {
            try {
                // @see https://www.php.net/manual/en/imagick.readimageblob.php
                $svgBytes = @file_get_contents($logoPath);

                if ($svgBytes !== false) {
                    $imagickInstance = new \Imagick();

                    // 📌 Explicitly declare the source format as SVG so
                    //     Imagick's librsvg delegate is used even though we
                    //     are reading raw bytes rather than a '.svg' file
                    //     path directly.
                    // @see https://www.php.net/manual/en/imagick.setformat.php
                    $imagickInstance->setFormat('svg');
                    $imagickInstance->readImageBlob($svgBytes);

                    // 📌 Rasterise to 32-bit PNG (preserves alpha transparency)
                    //     before handing the bytes to GD.
                    // @see https://www.php.net/manual/en/imagick.setimageformat.php
                    $imagickInstance->setImageFormat('png32');
                    $rasterisedPngBytes = $imagickInstance->getImageBlob();

                    // 🧹 Free the Imagick object's native resources.
                    $imagickInstance->clear();
                    $imagickInstance->destroy();

                    // @see https://www.php.net/manual/en/function.imagecreatefromstring.php
                    $imagickResult = @imagecreatefromstring($rasterisedPngBytes);

                    if ($imagickResult instanceof \GdImage) {
                        // 📌 Configure alpha blending exactly as the normal
                        //     raster path does below, then return directly
                        //     (this bypasses the exif/mime detection block,
                        //     which is irrelevant to an in-memory blob).
                        imagealphablending($imagickResult, true);
                        imagesavealpha($imagickResult, true);
                        return $imagickResult;
                    }
                }
            } catch (\Throwable $e) {
                // ⚠️ Imagick rasterisation failed (malformed SVG, missing
                //    librsvg delegate, etc.) - fall through to the
                //    non-silent warning + null return below rather than
                //    throwing out of this function.
                if (function_exists('logError')) {
                    logError(
                        'warning',
                        'Imagick SVG rasterisation failed for logo overlay',
                        'Path: ' . $logoPath . ' | ' . $e->getMessage()
                    );
                }
            }
        }

        // ------------------------------------------------------------
        // 3️⃣ NEITHER fallback is available: do NOT silently drop the logo.
        //    Log a clear, actionable warning and return null so the caller
        //    (overlayLogoOnRaster()) skips the overlay and returns the QR
        //    code UNCHANGED (still fully valid/scannable) - the difference
        //    from before this fix is that the reason is now DISCOVERABLE.
        // ------------------------------------------------------------
        if (function_exists('logError')) {
            logError(
                'warning',
                'Raster SVG logo overlay skipped: no PNG twin and no Imagick extension (#65)',
                'Path: ' . $logoPath . ' | Generate a PNG twin via '
                . '`php tools/render_logo_pngs.php` (in an environment with an '
                . 'SVG rasteriser) or enable the php-imagick extension so SVG '
                . 'logos can be rasterised for raster (PNG/JPG/GIF/WEBP) QR '
                . 'output. SVG-output QR codes are unaffected - they already '
                . 'embed the logo via overlayLogoOnSVG().'
            );
        }

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
