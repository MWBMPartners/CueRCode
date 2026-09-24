<?php
/**
 * ==============================================================================
 * 🖼️ CueRCode - Frame Renderer for QR Codes
 * ==============================================================================
 *
 * Path: _functions/fn_qr_frames.php
 *
 * Provides functions for adding decorative frames and labels around generated
 * QR codes. Supports both raster (GD-based) and vector (SVG) QR code outputs.
 *
 * Supported frame styles:
 *   - 'none'          : No frame (return as-is)
 *   - 'simple'        : Solid border around the QR code
 *   - 'rounded'       : Border with rounded corners
 *   - 'bottom-label'  : Frame with a text label below the QR code
 *   - 'top-label'     : Frame with a text label above the QR code
 *   - 'banner-bottom' : Coloured banner strip below with label text
 *   - 'banner-top'    : Coloured banner strip above with label text
 *   - 'pill'          : Pill-shaped (rounded ends) banner with label
 *
 * For frames with labels, text is rendered using imagettftext() (TrueType fonts)
 * when a font file is available, falling back to imagestring() (built-in GD fonts)
 * otherwise.
 *
 * @package     CueRCode
 * @subpackage  QR Generation
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2
 * @see         https://www.php.net/manual/en/function.imagettftext.php
 * @see         https://www.php.net/manual/en/function.imagestring.php
 * @see         https://www.php.net/manual/en/function.imagefilledrectangle.php
 * @see         https://developer.mozilla.org/en-US/docs/Web/SVG/Element/rect
 * @see         https://developer.mozilla.org/en-US/docs/Web/SVG/Element/text
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
// 🖼️ addFrameToRaster() - Add frame to raster QR code image
// ==============================================================================

/**
 * 🖼️ Add a decorative frame to a raster (GD-based) QR code image
 *
 * Creates a new, larger canvas, draws the frame elements (border, background,
 * label text), and places the QR code image inside the frame.
 *
 * For frames with text labels (bottom-label, top-label, banner-bottom,
 * banner-top, pill), text is rendered using imagettftext() if a TrueType
 * font file is available, otherwise falls back to imagestring().
 *
 * @param string $qrImageData The raw QR code image data (binary string)
 * @param array  $frameConfig Frame configuration from getFrameConfig():
 *   - 'style'       (string) Frame style name
 *   - 'label'       (string) Label text (for label/banner styles)
 *   - 'font'        (string) Path to TrueType font file
 *   - 'fontSize'    (int)    Font size in points (default: 16)
 *   - 'color'       (string) Frame colour as hex (default: '#000000')
 *   - 'bgColor'     (string) Frame background colour as hex (default: '#ffffff')
 *   - 'labelColor'  (string) Label text colour as hex (default: '#ffffff')
 *   - 'borderWidth' (int)    Border width in pixels (default: 4)
 *   - 'padding'     (int)    Padding around QR code in pixels (default: 20)
 * @param string $outputFormat The output image format: 'png', 'jpg', 'gif', 'webp'
 *
 * @return string The framed QR code image as a binary string
 *
 * @throws \RuntimeException If GD library is not available or image processing fails
 *
 * @see https://www.php.net/manual/en/function.imagecreatetruecolor.php
 * @see https://www.php.net/manual/en/function.imagecopyresampled.php
 * @see https://www.php.net/manual/en/function.imagettftext.php
 *
 * @example
 *   $framedQr = addFrameToRaster(
 *       $qrImageData,
 *       getFrameConfig(['frame_style' => 'bottom-label', 'frame_label' => 'SCAN ME']),
 *       'png'
 *   );
 */
function addFrameToRaster(string $qrImageData, array $frameConfig, string $outputFormat = 'png'): string
{
    // ✅ Verify GD library is available
    // @see https://www.php.net/manual/en/book.image.php
    if (!extension_loaded('gd')) {
        throw new \RuntimeException(
            'GD library is required for raster frame rendering but is not installed.'
        );
    }

    // 📌 Extract frame configuration
    if (isset($frameConfig['style'])) {
        $style = $frameConfig['style'];
    } else {
        $style = 'none';
    }

    if (isset($frameConfig['label'])) {
        $label = $frameConfig['label'];
    } else {
        $label = '';
    }

    if (isset($frameConfig['font'])) {
        $fontPath = $frameConfig['font'];
    } else {
        $fontPath = '';
    }

    if (isset($frameConfig['fontSize'])) {
        $fontSizeRaw = (int) $frameConfig['fontSize'];
    } else {
        $fontSizeRaw = 16;
    }
    $fontSize = max(8, min(72, $fontSizeRaw));

    if (isset($frameConfig['color'])) {
        $colorHex = $frameConfig['color'];
    } else {
        $colorHex = '#000000';
    }

    if (isset($frameConfig['bgColor'])) {
        $bgColorHex = $frameConfig['bgColor'];
    } else {
        $bgColorHex = '#ffffff';
    }

    if (isset($frameConfig['labelColor'])) {
        $labelColorHex = $frameConfig['labelColor'];
    } else {
        $labelColorHex = '#ffffff';
    }

    if (isset($frameConfig['borderWidth'])) {
        $borderWidthRaw = (int) $frameConfig['borderWidth'];
    } else {
        $borderWidthRaw = 4;
    }
    $borderWidth = max(1, min(20, $borderWidthRaw));

    if (isset($frameConfig['padding'])) {
        $paddingRaw = (int) $frameConfig['padding'];
    } else {
        $paddingRaw = 20;
    }
    $padding = max(0, min(100, $paddingRaw));

    // 📌 If no frame is requested, return the image unchanged
    if ($style === 'none') {
        return $qrImageData;
    }

    // ==================================================================
    // 📷 Load the QR code image
    // @see https://www.php.net/manual/en/function.imagecreatefromstring.php
    // ==================================================================
    $qrImage = @imagecreatefromstring($qrImageData);

    if ($qrImage === false) {
        throw new \RuntimeException(
            'Failed to load QR code image for frame rendering.'
        );
    }

    $qrWidth  = imagesx($qrImage);
    $qrHeight = imagesy($qrImage);

    // ==================================================================
    // 📐 Calculate canvas dimensions based on frame style
    // The frame adds padding, borders, and optionally a label area.
    // ==================================================================
    $labelHeight = 0;
    $hasLabel    = in_array($style, ['bottom-label', 'top-label', 'banner-bottom', 'banner-top', 'pill'], true);

    if ($hasLabel && $label !== '') {
        // 📌 Calculate label area height based on font size
        // Add extra padding above and below the text
        $labelHeight = (int) round($fontSize * 2.5);
    }

    // 📌 Calculate total canvas dimensions
    $totalPaddingX = ($padding * 2) + ($borderWidth * 2);
    $totalPaddingY = ($padding * 2) + ($borderWidth * 2);

    $canvasWidth  = $qrWidth + $totalPaddingX;
    $canvasHeight = $qrHeight + $totalPaddingY + $labelHeight;

    // ==================================================================
    // 🎨 Create the canvas and allocate colours
    // @see https://www.php.net/manual/en/function.imagecreatetruecolor.php
    // @see https://www.php.net/manual/en/function.imagecolorallocate.php
    // ==================================================================
    $canvas = imagecreatetruecolor($canvasWidth, $canvasHeight);

    if ($canvas === false) {
        // 🧹 No imagedestroy($qrImage) here - PHP releases it automatically
        // once this function throws and nothing refers to it any more.
        // imagedestroy() has been a no-op since PHP 8.0 and PHP 8.5 warns
        // when it is called (#117) - do not add it back.
        throw new \RuntimeException('Failed to create canvas for frame rendering.');
    }

    // 📌 Enable alpha blending for transparency support
    // @see https://www.php.net/manual/en/function.imagealphablending.php
    imagealphablending($canvas, true);
    imagesavealpha($canvas, true);

    // 📌 Allocate colours from hex values
    if (function_exists('hexToRgb')) {
        $bgRgb = hexToRgb($bgColorHex);
    } else {
        $bgRgb = [255, 255, 255];
    }

    if (function_exists('hexToRgb')) {
        $fgRgb = hexToRgb($colorHex);
    } else {
        $fgRgb = [0, 0, 0];
    }

    if (function_exists('hexToRgb')) {
        $labelRgb = hexToRgb($labelColorHex);
    } else {
        $labelRgb = [255, 255, 255];
    }

    $bgColor    = imagecolorallocate($canvas, $bgRgb[0], $bgRgb[1], $bgRgb[2]);
    $fgColor    = imagecolorallocate($canvas, $fgRgb[0], $fgRgb[1], $fgRgb[2]);
    $labelColor = imagecolorallocate($canvas, $labelRgb[0], $labelRgb[1], $labelRgb[2]);
    $whiteColor = imagecolorallocate($canvas, 255, 255, 255);

    // ==================================================================
    // 🎨 Draw the frame based on style
    // ==================================================================

    // 📌 Fill the entire canvas with the background colour
    // @see https://www.php.net/manual/en/function.imagefilledrectangle.php
    imagefilledrectangle($canvas, 0, 0, $canvasWidth - 1, $canvasHeight - 1, $bgColor);

    if ($style === 'simple' || $style === 'rounded') {
        // ==============================================================
        // 📌 Simple/Rounded border frame
        // Draw a coloured border rectangle, then a white interior.
        // ==============================================================

        // 📌 Draw the outer border
        // @see https://www.php.net/manual/en/function.imagefilledrectangle.php
        imagefilledrectangle($canvas, 0, 0, $canvasWidth - 1, $canvasHeight - 1, $fgColor);

        // 📌 Draw the white interior (inside the border)
        imagefilledrectangle(
            $canvas,
            $borderWidth,
            $borderWidth,
            $canvasWidth - 1 - $borderWidth,
            $canvasHeight - 1 - $borderWidth,
            $whiteColor
        );

        if ($style === 'rounded') {
            // 📌 Round the corners by drawing filled circles at each corner
            // This creates an approximation of rounded corners using GD.
            // @see https://www.php.net/manual/en/function.imagefilledellipse.php
            $cornerRadius = min(20, $padding);

            // 📌 Draw white circles at corners to round them
            imagefilledellipse($canvas, $cornerRadius, $cornerRadius, $cornerRadius * 2, $cornerRadius * 2, $bgColor);
            imagefilledellipse($canvas, $canvasWidth - $cornerRadius, $cornerRadius, $cornerRadius * 2, $cornerRadius * 2, $bgColor);
            imagefilledellipse($canvas, $cornerRadius, $canvasHeight - $cornerRadius, $cornerRadius * 2, $cornerRadius * 2, $bgColor);
            imagefilledellipse($canvas, $canvasWidth - $cornerRadius, $canvasHeight - $cornerRadius, $cornerRadius * 2, $cornerRadius * 2, $bgColor);
        }
    } elseif ($style === 'banner-bottom' || $style === 'banner-top' || $style === 'pill') {
        // ==============================================================
        // 📌 Banner/Pill frames: coloured strip with label text
        // ==============================================================

        // 📌 Draw the white background first
        imagefilledrectangle($canvas, 0, 0, $canvasWidth - 1, $canvasHeight - 1, $whiteColor);

        // 📌 Draw the coloured banner strip
        if ($style === 'banner-bottom' || $style === 'pill') {
            $bannerTop    = $canvasHeight - $labelHeight;
            $bannerBottom = $canvasHeight - 1;
        } else {
            // banner-top
            $bannerTop    = 0;
            $bannerBottom = $labelHeight - 1;
        }

        // @see https://www.php.net/manual/en/function.imagefilledrectangle.php
        imagefilledrectangle($canvas, 0, $bannerTop, $canvasWidth - 1, $bannerBottom, $fgColor);

        if ($style === 'pill') {
            // 📌 Round the banner ends for pill shape
            // @see https://www.php.net/manual/en/function.imagefilledellipse.php
            $pillRadius = (int) round($labelHeight / 2);
            imagefilledellipse($canvas, $pillRadius, $bannerTop + $pillRadius, $labelHeight, $labelHeight, $fgColor);
            imagefilledellipse($canvas, $canvasWidth - $pillRadius, $bannerTop + $pillRadius, $labelHeight, $labelHeight, $fgColor);
        }
    } elseif ($style === 'bottom-label' || $style === 'top-label') {
        // ==============================================================
        // 📌 Label-only frames: white background with text area
        // ==============================================================

        // 📌 Draw the white background
        imagefilledrectangle($canvas, 0, 0, $canvasWidth - 1, $canvasHeight - 1, $whiteColor);

        // 📌 Draw a subtle border
        // @see https://www.php.net/manual/en/function.imagerectangle.php
        imagerectangle($canvas, 0, 0, $canvasWidth - 1, $canvasHeight - 1, $fgColor);
    }

    // ==================================================================
    // 📍 Place the QR code on the canvas
    // Position depends on whether the label is on top or bottom.
    // @see https://www.php.net/manual/en/function.imagecopy.php
    // ==================================================================
    $qrX = $padding + $borderWidth;

    if ($style === 'top-label' || $style === 'banner-top') {
        $qrY = $labelHeight + $padding + $borderWidth;
    } else {
        $qrY = $padding + $borderWidth;
    }

    // @see https://www.php.net/manual/en/function.imagecopy.php
    imagecopy(
        $canvas,
        $qrImage,
        $qrX,
        $qrY,
        0,
        0,
        $qrWidth,
        $qrHeight
    );

    // ==================================================================
    // ✏️ Render the label text (if applicable)
    // Uses imagettftext() for TrueType fonts, or imagestring() as fallback.
    // @see https://www.php.net/manual/en/function.imagettftext.php
    // @see https://www.php.net/manual/en/function.imagestring.php
    // ==================================================================
    if ($hasLabel && $label !== '') {
        renderLabelText(
            $canvas,
            $label,
            $fontPath,
            $fontSize,
            $labelColor,
            $canvasWidth,
            $canvasHeight,
            $labelHeight,
            $style
        );
    }

    // ==================================================================
    // 📦 Render the final image to a binary string
    // ==================================================================
    $result = '';
    if (function_exists('renderGdImageToString')) {
        $result = renderGdImageToString($canvas, $outputFormat);
    } else {
        // 📌 Inline fallback if fn_qr_logo.php not loaded
        ob_start();
        imagepng($canvas, null, 9);
        $result = ob_get_clean();
        if ($result === false) {
            $result = '';
        }
    }

    // 🧹 $canvas and $qrImage are released automatically once this function
    // returns and nothing refers to them any more - imagedestroy() was
    // removed deliberately (#117): it has done NOTHING since PHP 8.0 (GD
    // images are objects, freed like any other object) and PHP 8.5 prints a
    // "deprecated" warning every time it is called. Do not add it back.
    return $result;
}

// ==============================================================================
// ✏️ renderLabelText() - Render label text on the frame canvas
// ==============================================================================

/**
 * ✏️ Render centred label text on the frame canvas
 *
 * Tries imagettftext() first (for TrueType fonts with anti-aliasing),
 * and falls back to imagestring() (built-in bitmap fonts) if the TTF
 * font file is not available.
 *
 * @param \GdImage $canvas       The canvas image resource
 * @param string   $label        The label text to render
 * @param string   $fontPath     Path to a TrueType font file (empty for fallback)
 * @param int      $fontSize     Font size in points
 * @param int      $labelColor   GD colour identifier for the text
 * @param int      $canvasWidth  Canvas width in pixels
 * @param int      $canvasHeight Canvas height in pixels
 * @param int      $labelHeight  Height of the label area in pixels
 * @param string   $style        Frame style name (to determine label position)
 *
 * @return void
 *
 * @see https://www.php.net/manual/en/function.imagettftext.php
 * @see https://www.php.net/manual/en/function.imagettfbbox.php
 * @see https://www.php.net/manual/en/function.imagestring.php
 */
function renderLabelText(
    \GdImage $canvas,
    string $label,
    string $fontPath,
    int $fontSize,
    int $labelColor,
    int $canvasWidth,
    int $canvasHeight,
    int $labelHeight,
    string $style
): void {
    // 📌 Determine the vertical position of the label text
    if ($style === 'top-label' || $style === 'banner-top') {
        // 📌 Label is at the top of the canvas
        $labelAreaTop = 0;
    } else {
        // 📌 Label is at the bottom of the canvas
        $labelAreaTop = $canvasHeight - $labelHeight;
    }

    $labelCenterY = $labelAreaTop + (int) round($labelHeight / 2);

    // ==================================================================
    // 📌 Try TrueType font rendering (imagettftext)
    // @see https://www.php.net/manual/en/function.imagettftext.php
    // @see https://www.php.net/manual/en/function.imagettfbbox.php
    // ==================================================================
    $useTtf = ($fontPath !== '' && file_exists($fontPath) && function_exists('imagettftext'));

    if ($useTtf) {
        // 📌 Calculate text bounding box for centering
        // @see https://www.php.net/manual/en/function.imagettfbbox.php
        $bbox = imagettfbbox($fontSize, 0, $fontPath, $label);

        if ($bbox !== false) {
            $textWidth  = abs($bbox[2] - $bbox[0]);
            $textHeight = abs($bbox[7] - $bbox[1]);

            // 📌 Calculate centred position
            $textX = (int) round(($canvasWidth - $textWidth) / 2);
            $textY = $labelCenterY + (int) round($textHeight / 2);

            // 📌 Render the text with TrueType font
            // @see https://www.php.net/manual/en/function.imagettftext.php
            imagettftext(
                $canvas,
                $fontSize,
                0,           // Angle (0 = horizontal)
                $textX,
                $textY,
                $labelColor,
                $fontPath,
                $label
            );

            return;
        }
    }

    // ==================================================================
    // 📌 Fallback: use built-in GD bitmap font (imagestring)
    // GD built-in fonts are numbered 1-5 (5 being the largest).
    // @see https://www.php.net/manual/en/function.imagestring.php
    // @see https://www.php.net/manual/en/function.imagefontwidth.php
    // @see https://www.php.net/manual/en/function.imagefontheight.php
    // ==================================================================

    // 📌 Choose the largest built-in font (5) for best readability
    $builtinFont = 5;

    // 📌 Calculate text dimensions using built-in font metrics
    // @see https://www.php.net/manual/en/function.imagefontwidth.php
    $charWidth  = imagefontwidth($builtinFont);
    $charHeight = imagefontheight($builtinFont);
    $textWidth  = $charWidth * strlen($label);

    // 📌 Calculate centred position
    $textX = (int) round(($canvasWidth - $textWidth) / 2);
    $textY = $labelCenterY - (int) round($charHeight / 2);

    // 📌 Render the text with built-in font
    // @see https://www.php.net/manual/en/function.imagestring.php
    imagestring($canvas, $builtinFont, $textX, $textY, $label, $labelColor);
}

// ==============================================================================
// 📐 addFrameToSVG() - Add frame to SVG QR code
// ==============================================================================

/**
 * 📐 Add a decorative frame to an SVG QR code
 *
 * Wraps the QR code SVG content in an outer SVG element with frame
 * elements (rect, text, path) drawn around and below/above the QR code.
 *
 * @param string $svgContent The SVG markup string of the QR code
 * @param array  $frameConfig Frame configuration from getFrameConfig()
 *
 * @return string The modified SVG markup with frame elements
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/SVG/Element/svg
 * @see https://developer.mozilla.org/en-US/docs/Web/SVG/Element/rect
 * @see https://developer.mozilla.org/en-US/docs/Web/SVG/Element/text
 *
 * @example
 *   $framedSvg = addFrameToSVG(
 *       $svgContent,
 *       getFrameConfig(['frame_style' => 'bottom-label', 'frame_label' => 'SCAN ME'])
 *   );
 */
function addFrameToSVG(string $svgContent, array $frameConfig): string
{
    // 📌 Extract frame configuration
    if (isset($frameConfig['style'])) {
        $style = $frameConfig['style'];
    } else {
        $style = 'none';
    }

    if (isset($frameConfig['label'])) {
        $label = $frameConfig['label'];
    } else {
        $label = '';
    }

    if (isset($frameConfig['fontSize'])) {
        $svgFontSizeRaw = (int) $frameConfig['fontSize'];
    } else {
        $svgFontSizeRaw = 16;
    }
    $fontSize = max(8, min(72, $svgFontSizeRaw));

    if (isset($frameConfig['color'])) {
        $colorHex = $frameConfig['color'];
    } else {
        $colorHex = '#000000';
    }

    if (isset($frameConfig['bgColor'])) {
        $bgColorHex = $frameConfig['bgColor'];
    } else {
        $bgColorHex = '#ffffff';
    }

    if (isset($frameConfig['labelColor'])) {
        $labelColorHex = $frameConfig['labelColor'];
    } else {
        $labelColorHex = '#ffffff';
    }

    if (isset($frameConfig['borderWidth'])) {
        $svgBorderWidthRaw = (int) $frameConfig['borderWidth'];
    } else {
        $svgBorderWidthRaw = 4;
    }
    $borderWidth = max(1, min(20, $svgBorderWidthRaw));

    if (isset($frameConfig['padding'])) {
        $svgPaddingRaw = (int) $frameConfig['padding'];
    } else {
        $svgPaddingRaw = 20;
    }
    $padding = max(0, min(100, $svgPaddingRaw));

    // 📌 If no frame is requested, return the SVG unchanged
    if ($style === 'none') {
        return $svgContent;
    }

    // ==================================================================
    // 📐 Parse original SVG dimensions
    // @see https://www.php.net/manual/en/function.preg-match.php
    // ==================================================================
    $svgWidth  = 0;
    $svgHeight = 0;

    // 📌 Extract viewBox dimensions
    if (preg_match('/viewBox\s*=\s*"([^"]+)"/', $svgContent, $vbMatch) === 1) {
        $vbParts = preg_split('/[\s,]+/', trim($vbMatch[1]));
        if (count($vbParts) >= 4) {
            $svgWidth  = (float) $vbParts[2];
            $svgHeight = (float) $vbParts[3];
        }
    }

    // 📌 Fallback to width/height attributes
    if ($svgWidth <= 0) {
        if (preg_match('/\bwidth\s*=\s*"([0-9.]+)"/', $svgContent, $wMatch) === 1) {
            $svgWidth = (float) $wMatch[1];
        } else {
            $svgWidth = 200;
        }
    }
    if ($svgHeight <= 0) {
        if (preg_match('/\bheight\s*=\s*"([0-9.]+)"/', $svgContent, $hMatch) === 1) {
            $svgHeight = (float) $hMatch[1];
        } else {
            $svgHeight = 200;
        }
    }

    // ==================================================================
    // 📐 Calculate frame dimensions
    // ==================================================================
    $labelHeight = 0;
    $hasLabel    = in_array($style, ['bottom-label', 'top-label', 'banner-bottom', 'banner-top', 'pill'], true);

    if ($hasLabel && $label !== '') {
        $labelHeight = $fontSize * 2.5;
    }

    $frameWidth  = $svgWidth + ($padding * 2) + ($borderWidth * 2);
    $frameHeight = $svgHeight + ($padding * 2) + ($borderWidth * 2) + $labelHeight;

    // 📌 Calculate QR code position within the frame
    $qrX = $padding + $borderWidth;

    if ($style === 'top-label' || $style === 'banner-top') {
        $qrY = $labelHeight + $padding + $borderWidth;
    } else {
        $qrY = $padding + $borderWidth;
    }

    // ==================================================================
    // 📋 Build the framed SVG
    // Escape colour values for safe XML attribute inclusion.
    // @see https://www.php.net/manual/en/function.htmlspecialchars.php
    // ==================================================================
    $safeColor      = htmlspecialchars($colorHex, ENT_QUOTES, 'UTF-8');
    $safeBgColor    = htmlspecialchars($bgColorHex, ENT_QUOTES, 'UTF-8');
    $safeLabelColor = htmlspecialchars($labelColorHex, ENT_QUOTES, 'UTF-8');
    $safeLabel      = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');

    // 📌 Start the outer SVG wrapper
    $output = '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
    $output .= '<svg xmlns="http://www.w3.org/2000/svg"'
        . ' xmlns:xlink="http://www.w3.org/1999/xlink"'
        . ' viewBox="0 0 ' . $frameWidth . ' ' . $frameHeight . '"'
        . ' width="' . $frameWidth . '"'
        . ' height="' . $frameHeight . '">' . PHP_EOL;

    // ==================================================================
    // 🎨 Draw frame elements based on style
    // ==================================================================

    if ($style === 'simple') {
        // 📌 Simple border: coloured rectangle + white interior
        $output .= '  <rect x="0" y="0" width="' . $frameWidth . '" height="' . $frameHeight . '"'
            . ' fill="' . $safeColor . '"/>' . PHP_EOL;
        $output .= '  <rect x="' . $borderWidth . '" y="' . $borderWidth . '"'
            . ' width="' . ($frameWidth - $borderWidth * 2) . '"'
            . ' height="' . ($frameHeight - $borderWidth * 2) . '"'
            . ' fill="#ffffff"/>' . PHP_EOL;

    } elseif ($style === 'rounded') {
        // 📌 Rounded border
        $cornerRadius = min(20, $padding);
        $output .= '  <rect x="0" y="0" width="' . $frameWidth . '" height="' . $frameHeight . '"'
            . ' rx="' . $cornerRadius . '" ry="' . $cornerRadius . '"'
            . ' fill="' . $safeColor . '"/>' . PHP_EOL;
        $output .= '  <rect x="' . $borderWidth . '" y="' . $borderWidth . '"'
            . ' width="' . ($frameWidth - $borderWidth * 2) . '"'
            . ' height="' . ($frameHeight - $borderWidth * 2) . '"'
            . ' rx="' . max(0, $cornerRadius - $borderWidth) . '"'
            . ' ry="' . max(0, $cornerRadius - $borderWidth) . '"'
            . ' fill="#ffffff"/>' . PHP_EOL;

    } elseif ($style === 'banner-bottom' || $style === 'banner-top' || $style === 'pill') {
        // 📌 Banner/Pill: white background + coloured banner strip
        $output .= '  <rect x="0" y="0" width="' . $frameWidth . '" height="' . $frameHeight . '"'
            . ' fill="#ffffff"/>' . PHP_EOL;

        if ($style === 'banner-top') {
            $bannerY = 0;
        } else {
            $bannerY = $frameHeight - $labelHeight;
        }

        if ($style === 'pill') {
            $rx = round($labelHeight / 2);
        } else {
            $rx = 0;
        }

        $output .= '  <rect x="0" y="' . $bannerY . '"'
            . ' width="' . $frameWidth . '" height="' . $labelHeight . '"'
            . ' rx="' . $rx . '" ry="' . $rx . '"'
            . ' fill="' . $safeColor . '"/>' . PHP_EOL;

    } elseif ($style === 'bottom-label' || $style === 'top-label') {
        // 📌 Label frame: white background with subtle border
        $output .= '  <rect x="0" y="0" width="' . $frameWidth . '" height="' . $frameHeight . '"'
            . ' fill="#ffffff" stroke="' . $safeColor . '" stroke-width="' . $borderWidth . '"/>' . PHP_EOL;
    }

    // ==================================================================
    // 📌 Embed the original QR code SVG as a nested SVG element
    // Strip the XML declaration and outer <svg> tags from the original content.
    // @see https://www.php.net/manual/en/function.preg-replace.php
    // ==================================================================

    // 📌 Remove XML declaration from inner SVG
    $innerSvg = preg_replace('/<\?xml[^?]*\?>/', '', $svgContent);

    // 📌 Wrap the inner SVG in a positioned <svg> element
    $output .= '  <svg x="' . $qrX . '" y="' . $qrY . '"'
        . ' width="' . $svgWidth . '" height="' . $svgHeight . '">' . PHP_EOL;
    $output .= '    ' . trim($innerSvg) . PHP_EOL;
    $output .= '  </svg>' . PHP_EOL;

    // ==================================================================
    // ✏️ Render the label text (if applicable)
    // @see https://developer.mozilla.org/en-US/docs/Web/SVG/Element/text
    // ==================================================================
    if ($hasLabel && $label !== '') {
        if ($style === 'top-label' || $style === 'banner-top') {
            $textY = $labelHeight / 2 + $fontSize / 3;
        } else {
            $textY = ($frameHeight - $labelHeight) + ($labelHeight / 2) + ($fontSize / 3);
        }

        $textX = $frameWidth / 2;

        // 📌 Determine text colour based on style
        if (in_array($style, ['banner-bottom', 'banner-top', 'pill'], true)) {
            $textFill = $safeLabelColor;
        } else {
            $textFill = $safeColor;
        }

        $output .= '  <text x="' . $textX . '" y="' . round($textY, 2) . '"'
            . ' text-anchor="middle"'
            . ' dominant-baseline="middle"'
            . ' font-family="Arial, Helvetica, sans-serif"'
            . ' font-size="' . $fontSize . '"'
            . ' font-weight="bold"'
            . ' fill="' . $textFill . '">'
            . $safeLabel . '</text>' . PHP_EOL;
    }

    // 📌 Close the outer SVG
    $output .= '</svg>' . PHP_EOL;

    return $output;
}

// ==============================================================================
// 📋 getFrameConfig() - Extract frame configuration from customization
// ==============================================================================

/**
 * 📋 Extract and normalise frame-related settings from a customization array
 *
 * Takes the raw customization array from the UI and extracts only the
 * frame-related settings, applying defaults for any missing values.
 *
 * @param array $customization The full customization array from the UI
 *
 * @return array Normalised frame configuration array:
 *   - 'style'       (string) Frame style name (default: 'none')
 *   - 'label'       (string) Label text (default: '')
 *   - 'font'        (string) Path to TrueType font file (default: '')
 *   - 'fontSize'    (int)    Font size in points (default: 16)
 *   - 'color'       (string) Frame colour as hex (default: '#000000')
 *   - 'bgColor'     (string) Frame background colour as hex (default: '#ffffff')
 *   - 'labelColor'  (string) Label text colour as hex (default: '#ffffff')
 *   - 'borderWidth' (int)    Border width in pixels (default: 4)
 *   - 'padding'     (int)    Padding around QR code in pixels (default: 20)
 *
 * @example
 *   $frameConfig = getFrameConfig([
 *       'frame_style' => 'banner-bottom',
 *       'frame_label' => 'SCAN ME',
 *       'frame_color' => '#2c7a7b',
 *   ]);
 *   // Returns: ['style' => 'banner-bottom', 'label' => 'SCAN ME', 'color' => '#2c7a7b', ...]
 */
function getFrameConfig(array $customization): array
{
    // 📌 Extract frame style with validation
    if (isset($customization['frame_style'])) {
        $frameStyleRawValue = $customization['frame_style'];
    } elseif (isset($customization['frame'])) {
        $frameStyleRawValue = $customization['frame'];
    } else {
        $frameStyleRawValue = 'none';
    }
    $style = strtolower(trim($frameStyleRawValue));

    // 📌 Validate against supported frame styles
    $validStyles = [
        'none', 'simple', 'rounded',
        'bottom-label', 'top-label',
        'banner-bottom', 'banner-top',
        'pill',
    ];

    if (!in_array($style, $validStyles, true)) {
        // ⚠️ Unknown style - default to 'none'
        $style = 'none';
    }

    // 📌 Extract label text
    if (isset($customization['frame_label'])) {
        $frameLabelRawValue = $customization['frame_label'];
    } elseif (isset($customization['label'])) {
        $frameLabelRawValue = $customization['label'];
    } else {
        $frameLabelRawValue = '';
    }
    $label = trim($frameLabelRawValue);

    // 📌 Extract font path
    if (isset($customization['frame_font'])) {
        $frameFontRawValue = $customization['frame_font'];
    } elseif (isset($customization['font'])) {
        $frameFontRawValue = $customization['font'];
    } else {
        $frameFontRawValue = '';
    }
    $font = trim($frameFontRawValue);

    // 📌 Try to find a default font if none specified
    if ($font === '') {
        $font = findDefaultFont();
    }

    // 📌 Extract font size (clamped to reasonable range)
    if (isset($customization['frame_font_size'])) {
        $fontSizeRawValue = $customization['frame_font_size'];
    } elseif (isset($customization['fontSize'])) {
        $fontSizeRawValue = $customization['fontSize'];
    } else {
        $fontSizeRawValue = 16;
    }
    $fontSize = (int) $fontSizeRawValue;
    $fontSize = max(8, min(72, $fontSize));

    // 📌 Extract colours
    if (isset($customization['frame_color'])) {
        $colorRawValue = $customization['frame_color'];
    } elseif (isset($customization['color'])) {
        $colorRawValue = $customization['color'];
    } else {
        $colorRawValue = '#000000';
    }
    $color = trim($colorRawValue);

    if (isset($customization['frame_bg_color'])) {
        $bgColorRawValue = $customization['frame_bg_color'];
    } elseif (isset($customization['bgColor'])) {
        $bgColorRawValue = $customization['bgColor'];
    } else {
        $bgColorRawValue = '#ffffff';
    }
    $bgColor = trim($bgColorRawValue);

    if (isset($customization['frame_label_color'])) {
        $labelColorRawValue = $customization['frame_label_color'];
    } elseif (isset($customization['labelColor'])) {
        $labelColorRawValue = $customization['labelColor'];
    } else {
        $labelColorRawValue = '#ffffff';
    }
    $labelColor = trim($labelColorRawValue);

    // 📌 Extract border width and padding
    if (isset($customization['frame_border_width'])) {
        $borderWidthRawValue = $customization['frame_border_width'];
    } elseif (isset($customization['borderWidth'])) {
        $borderWidthRawValue = $customization['borderWidth'];
    } else {
        $borderWidthRawValue = 4;
    }
    $borderWidth = (int) $borderWidthRawValue;
    $borderWidth = max(1, min(20, $borderWidth));

    if (isset($customization['frame_padding'])) {
        $paddingRawValue = $customization['frame_padding'];
    } elseif (isset($customization['padding'])) {
        $paddingRawValue = $customization['padding'];
    } else {
        $paddingRawValue = 20;
    }
    $padding = (int) $paddingRawValue;
    $padding = max(0, min(100, $padding));

    return [
        'style'       => $style,
        'label'       => $label,
        'font'        => $font,
        'fontSize'    => $fontSize,
        'color'       => $color,
        'bgColor'     => $bgColor,
        'labelColor'  => $labelColor,
        'borderWidth' => $borderWidth,
        'padding'     => $padding,
    ];
}

// ==============================================================================
// 🔍 findDefaultFont() - Locate a default TrueType font file
// ==============================================================================

/**
 * 🔍 Locate a default TrueType font file on the system
 *
 * Searches common font directories for a suitable default font.
 * Checks the application's assets directory first, then system font
 * directories for commonly available sans-serif fonts.
 *
 * @return string Path to a TrueType font file, or empty string if none found
 *
 * @see https://www.php.net/manual/en/function.file-exists.php
 * @see https://www.php.net/manual/en/function.glob.php
 */
function findDefaultFont(): string
{
    // 📌 Check application's font directory first
    if (defined('CUERCODE_SITE_ROOT')) {
        $appFonts = [
            CUERCODE_SITE_ROOT . 'public_html' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'fonts' . DIRECTORY_SEPARATOR . 'OpenSans-Bold.ttf',
            CUERCODE_SITE_ROOT . 'public_html' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'fonts' . DIRECTORY_SEPARATOR . 'Roboto-Bold.ttf',
            CUERCODE_SITE_ROOT . 'public_html' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'fonts' . DIRECTORY_SEPARATOR . 'Arial.ttf',
        ];

        foreach ($appFonts as $fontPath) {
            if (file_exists($fontPath)) {
                return $fontPath;
            }
        }
    }

    // 📌 Check common system font directories
    // @see https://www.php.net/manual/en/function.file-exists.php
    $systemFonts = [];

    // 📌 Linux system fonts
    if (PHP_OS_FAMILY === 'Linux') {
        $systemFonts = [
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
            '/usr/share/fonts/truetype/ubuntu/Ubuntu-Bold.ttf',
            '/usr/share/fonts/truetype/freefont/FreeSansBold.ttf',
            '/usr/share/fonts/TTF/DejaVuSans-Bold.ttf',
        ];
    }

    // 📌 macOS system fonts
    if (PHP_OS_FAMILY === 'Darwin') {
        $systemFonts = [
            '/System/Library/Fonts/Helvetica.ttc',
            '/System/Library/Fonts/SFPro.ttf',
            '/Library/Fonts/Arial.ttf',
            '/System/Library/Fonts/Supplemental/Arial Bold.ttf',
        ];
    }

    // 📌 Windows system fonts
    if (PHP_OS_FAMILY === 'Windows') {
        $systemFonts = [
            'C:\\Windows\\Fonts\\arial.ttf',
            'C:\\Windows\\Fonts\\arialbd.ttf',
            'C:\\Windows\\Fonts\\calibri.ttf',
            'C:\\Windows\\Fonts\\segoeui.ttf',
        ];
    }

    foreach ($systemFonts as $fontPath) {
        if (file_exists($fontPath)) {
            return $fontPath;
        }
    }

    // ❌ No font found - imagestring() fallback will be used
    return '';
}
