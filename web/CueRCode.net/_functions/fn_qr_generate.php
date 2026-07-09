<?php
/**
 * ==============================================================================
 * 🎯 CueRCode - QR Code Generation Orchestrator
 * ==============================================================================
 *
 * Path: _functions/fn_qr_generate.php
 *
 * The main entry point for QR code generation. This file orchestrates the
 * entire generation pipeline:
 *
 *   1. Validate inputs
 *   2. Build the payload (fn_qr_payloads.php)
 *   3. Build QR options (fn_qr_options.php)
 *   4. Generate the QR code via chillerlan/php-qrcode
 *   5. Post-process: logo overlay (fn_qr_logo.php)
 *   6. Post-process: frame rendering (fn_qr_frames.php)
 *   7. Generate UUID v4 for the QR code
 *   8. Save record to tblQRCodes
 *   9. Optionally save file to _uploads/qrcodes/
 *  10. Log activity
 *  11. Return result array
 *
 * Also provides:
 *   - generatePreview() for quick, smaller preview images
 *   - saveQRToFile() for saving QR images to the filesystem
 *   - generateUUIDv4() for RFC 4122 compliant UUID generation
 *
 * @package     CueRCode
 * @subpackage  QR Generation
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2
 * @see         https://php-qrcode.readthedocs.io/ chillerlan/php-qrcode documentation
 * @see         https://github.com/chillerlan/php-qrcode chillerlan/php-qrcode GitHub
 * @see         https://datatracker.ietf.org/doc/html/rfc4122 UUID v4 specification
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
// 📦 Import QR code library class
// ==============================================================================
// @see https://www.php.net/manual/en/language.namespaces.importing.php
// ==============================================================================

use chillerlan\QRCode\QRCode;

// ==============================================================================
// 🎯 generateQRCode() - Main QR Code Generation Function
// ==============================================================================

/**
 * 🎯 Generate a complete QR code with all customizations applied
 *
 * This is the primary function for generating QR codes. It orchestrates
 * the entire pipeline from input validation through to file saving and
 * activity logging.
 *
 * The returned array contains all information needed to display or
 * download the generated QR code.
 *
 * @param string $typeName      The QR code type (e.g., 'url', 'vcard', 'wifi')
 * @param array  $inputData     Type-specific input data (e.g., ['url' => 'https://example.com'])
 * @param array  $customization Visual customization options:
 *   - 'format'        (string)  Output format: png, jpg, gif, webp, svg, pdf
 *   - 'size'          (int)     Target size in pixels (default: 800)
 *   - 'ecc'           (string)  Error correction level: L, M, Q, H
 *   - 'fg_color'      (string)  Foreground colour as hex
 *   - 'bg_color'      (string)  Background colour as hex
 *   - 'marker_color'  (string)  Finder pattern colour as hex
 *   - 'pattern'       (string)  Module shape: square, round, dot
 *   - 'logo'          (mixed)   Logo input (ID, base64, or path)
 *   - 'logo_size_pct' (int)     Logo size as % of QR code
 *   - 'transparent'   (bool)    Transparent background
 *   - 'gradient'      (array)   SVG gradient config
 *   - 'frame_style'   (string)  Frame style name
 *   - 'frame_label'   (string)  Frame label text
 *   - 'frame_color'   (string)  Frame colour as hex
 *   - 'save_file'     (bool)    Whether to save to filesystem (default: true)
 *
 * @return array Result array:
 *   - 'success'  (bool)        Whether generation was successful
 *   - 'uuid'     (string)      UUID v4 identifier for this QR code
 *   - 'data'     (string)      Base64 data URI or raw SVG/PDF content
 *   - 'mimeType' (string)      MIME type of the output
 *   - 'filePath' (string|null) Absolute file path if saved to disk
 *   - 'payload'  (string)      The encoded payload data
 *   - 'error'    (string|null) Error message if generation failed
 *
 * @see https://php-qrcode.readthedocs.io/en/main/Usage/Quickstart.html
 *
 * @example
 *   $result = generateQRCode('url', ['url' => 'https://example.com'], [
 *       'format'   => 'png',
 *       'size'     => 1000,
 *       'ecc'      => 'H',
 *       'fg_color' => '#333333',
 *       'logo'     => 5,
 *   ]);
 *
 *   if ($result['success']) {
 *       echo '<img src="' . $result['data'] . '" alt="QR Code">';
 *   } else {
 *       echo 'Error: ' . $result['error'];
 *   }
 */
function generateQRCode(string $typeName, array $inputData, array $customization = []): array
{
    // 📌 Initialise the result array with default (error) values
    $result = [
        'success'  => false,
        'uuid'     => '',
        'data'     => '',
        'mimeType' => '',
        'filePath' => null,
        'payload'  => '',
        'error'    => null,
    ];

    // ==================================================================
    // 📌 Step 1: Validate inputs
    // ==================================================================
    try {
        // ❌ Validate type name
        if (trim($typeName) === '') {
            throw new \InvalidArgumentException('QR code type name is required.');
        }

        // ❌ Validate input data
        if (empty($inputData)) {
            throw new \InvalidArgumentException('Input data is required for QR code generation.');
        }

        // 📌 Sanitise input data if sanitizeInput() is available
        // @see fn_validation.php - sanitizeInput()
        if (function_exists('sanitizeInput')) {
            foreach ($inputData as $key => $value) {
                if (is_string($value)) {
                    // 📌 Note: we use the raw value for payload building,
                    // but sanitise for storage. Some payloads (URLs, vCards)
                    // need special characters preserved.
                    $inputData[$key] = $value;
                }
            }
        }
    } catch (\InvalidArgumentException $e) {
        $result['error'] = $e->getMessage();
        if (function_exists('logError')) {
            logError('warning', 'QR generation input validation failed', $e->getMessage());
        }
        return $result;
    }

    // ==================================================================
    // 📌 Step 2: Build the payload
    // @see fn_qr_payloads.php - buildQRPayload()
    // ==================================================================
    try {
        if (!function_exists('buildQRPayload')) {
            throw new \RuntimeException(
                'Payload builder not available. Ensure fn_qr_payloads.php is loaded.'
            );
        }

        $payload = buildQRPayload($typeName, $inputData);
        $result['payload'] = $payload;
    } catch (\Throwable $e) {
        $result['error'] = 'Failed to build QR code payload: ' . $e->getMessage();
        if (function_exists('logError')) {
            logError('error', 'QR payload build failed', $e->getMessage(), [
                'typeName' => $typeName,
            ]);
        }
        return $result;
    }

    // ==================================================================
    // 📌 Step 3: Build QR options
    // @see fn_qr_options.php - buildQROptions()
    // ==================================================================
    if (isset($customization['format'])) {
        $genFormatValue = $customization['format'];
    } else {
        $genFormatValue = 'png';
    }
    $format = strtolower(trim($genFormatValue));

    try {
        if (!function_exists('buildQROptions')) {
            throw new \RuntimeException(
                'Options builder not available. Ensure fn_qr_options.php is loaded.'
            );
        }

        // 📌 Pass the payload length to options builder for scale calculation
        $customization['payload'] = $payload;

        // 📌 If a logo is specified but ECC is not set to H, upgrade it
        // Logo overlays require high error correction for reliable scanning.
        if (!empty($customization['logo'])) {
            if (isset($customization['ecc'])) {
                $currentEccValue = $customization['ecc'];
            } else {
                $currentEccValue = 'M';
            }
            $currentEcc = strtoupper(trim($currentEccValue));
            if ($currentEcc !== 'H') {
                $customization['ecc'] = 'H';
            }
        }

        $qrOptions = buildQROptions($customization);
    } catch (\Throwable $e) {
        $result['error'] = 'Failed to build QR code options: ' . $e->getMessage();
        if (function_exists('logError')) {
            logError('error', 'QR options build failed', $e->getMessage());
        }
        return $result;
    }

    // ==================================================================
    // 📌 Step 4: Generate the QR code
    // @see https://php-qrcode.readthedocs.io/en/main/Usage/Quickstart.html
    // ==================================================================
    try {
        // 📌 Create the QRCode instance with our configured options
        // @see https://github.com/chillerlan/php-qrcode
        $qrcode = new QRCode($qrOptions);

        // 📌 Render the QR code from the payload string
        // The render() method returns a string (binary image data or SVG markup).
        $qrOutput = $qrcode->render($payload);
    } catch (\Throwable $e) {
        $result['error'] = 'QR code generation failed: ' . $e->getMessage();
        if (function_exists('logError')) {
            logError('error', 'QR code render failed', $e->getMessage(), [
                'typeName'      => $typeName,
                'payloadLength' => strlen($payload),
                'format'        => $format,
            ]);
        }
        return $result;
    }

    // ==================================================================
    // 📌 Determine output format details
    // @see fn_qr_options.php - mapOutputFormat()
    // ==================================================================
    if (function_exists('mapOutputFormat')) {
        $formatMapping = mapOutputFormat($format);
    } else {
        $formatMapping = ['mimeType' => 'image/png', 'fileExtension' => 'png'];
    }

    $mimeType      = $formatMapping['mimeType'];
    $fileExtension = $formatMapping['fileExtension'];
    $result['mimeType'] = $mimeType;

    // ==================================================================
    // 📌 Step 5: Post-process - Logo overlay (if specified)
    // @see fn_qr_logo.php - overlayLogoOnRaster(), overlayLogoOnSVG()
    // ==================================================================
    if (!empty($customization['logo'])) {
        try {
            // 📌 Resolve the logo path
            $logoPath = null;
            if (function_exists('getLogoPath')) {
                $logoPath = getLogoPath($customization['logo']);
            }

            if ($logoPath !== null) {
                if (isset($customization['logo_size_pct'])) {
                    $logoSizePctVal = (int) $customization['logo_size_pct'];
                } else {
                    $logoSizePctVal = 20;
                }

                if (isset($customization['logo_padding'])) {
                    $logoPaddingVal = (bool) $customization['logo_padding'];
                } else {
                    $logoPaddingVal = true;
                }

                if (isset($customization['logo_padding_pct'])) {
                    $logoPaddingPctVal = (int) $customization['logo_padding_pct'];
                } else {
                    $logoPaddingPctVal = 15;
                }

                if (isset($customization['logo_padding_shape'])) {
                    $logoPaddingShapeVal = $customization['logo_padding_shape'];
                } else {
                    $logoPaddingShapeVal = 'circle';
                }

                $logoConfig = [
                    'size_pct'      => $logoSizePctVal,
                    'padding'       => $logoPaddingVal,
                    'padding_pct'   => $logoPaddingPctVal,
                    'padding_shape' => $logoPaddingShapeVal,
                    'output_format' => $format,
                ];

                if ($format === 'svg') {
                    // 📌 SVG logo overlay
                    if (function_exists('overlayLogoOnSVG')) {
                        $qrOutput = overlayLogoOnSVG($qrOutput, $logoPath, $logoConfig);
                    }
                } elseif ($format !== 'pdf') {
                    // 📌 Raster logo overlay (PNG, JPG, GIF, WEBP)
                    if (function_exists('overlayLogoOnRaster')) {
                        $qrOutput = overlayLogoOnRaster($qrOutput, $logoPath, $logoConfig);
                    }
                }
            }
        } catch (\Throwable $e) {
            // ⚠️ Logo overlay failed - continue without logo
            // The QR code itself is still valid; the logo is a nice-to-have.
            if (function_exists('logError')) {
                logError('warning', 'Logo overlay failed', $e->getMessage());
            }
        }
    }

    // ==================================================================
    // 📌 Step 6: Post-process - Frame rendering (if specified)
    // @see fn_qr_frames.php - addFrameToRaster(), addFrameToSVG()
    // ==================================================================
    if (isset($customization['frame_style'])) {
        $frameStyleValue = $customization['frame_style'];
    } elseif (isset($customization['frame'])) {
        $frameStyleValue = $customization['frame'];
    } else {
        $frameStyleValue = 'none';
    }
    $frameStyle = strtolower(trim($frameStyleValue));

    if ($frameStyle !== 'none' && $frameStyle !== '') {
        try {
            // 📌 Build the frame configuration
            $frameConfig = [];
            if (function_exists('getFrameConfig')) {
                $frameConfig = getFrameConfig($customization);
            }

            if (isset($frameConfig['style'])) {
                $frameConfigStyleVal = $frameConfig['style'];
            } else {
                $frameConfigStyleVal = 'none';
            }
            if (!empty($frameConfig) && $frameConfigStyleVal !== 'none') {
                if ($format === 'svg') {
                    // 📌 SVG frame rendering
                    if (function_exists('addFrameToSVG')) {
                        $qrOutput = addFrameToSVG($qrOutput, $frameConfig);
                    }
                } elseif ($format !== 'pdf') {
                    // 📌 Raster frame rendering
                    if (function_exists('addFrameToRaster')) {
                        $qrOutput = addFrameToRaster($qrOutput, $frameConfig, $format);
                    }
                }
            }
        } catch (\Throwable $e) {
            // ⚠️ Frame rendering failed - continue without frame
            if (function_exists('logError')) {
                logError('warning', 'Frame rendering failed', $e->getMessage());
            }
        }
    }

    // ==================================================================
    // 📌 Step 7: Generate UUID v4
    // @see https://datatracker.ietf.org/doc/html/rfc4122
    // ==================================================================
    $uuid = generateUUIDv4();
    $result['uuid'] = $uuid;

    // ==================================================================
    // 📌 Step 8: Format the output data
    // For raster formats, encode as base64 data URI.
    // For SVG and PDF, return the raw content.
    // ==================================================================
    if ($format === 'svg' || $format === 'pdf') {
        // 📌 SVG and PDF: return raw content
        $result['data'] = $qrOutput;
    } else {
        // 📌 Raster formats: encode as base64 data URI for HTML embedding
        // @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Basics_of_HTTP/Data_URIs
        // @see https://www.php.net/manual/en/function.base64-encode.php
        $result['data'] = 'data:' . $mimeType . ';base64,' . base64_encode($qrOutput);
    }

    // ==================================================================
    // 📌 Step 9: Save record to tblQRCodes
    // @see https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php
    // ==================================================================
    $qrCodeId = saveQRCodeRecord($uuid, $typeName, $payload, $customization, $format);

    // ==================================================================
    // 📌 Step 10: Optionally save file to _uploads/qrcodes/
    // @see fn_qr_generate.php - saveQRToFile()
    // ==================================================================
    if (isset($customization['save_file'])) {
        $saveFile = (bool) $customization['save_file'];
    } else {
        $saveFile = true;
    }

    if ($saveFile) {
        try {
            $filePath = saveQRToFile($qrOutput, $uuid, $fileExtension);
            $result['filePath'] = $filePath;

            // 📌 Update the database record with the file path
            if ($filePath !== null && $qrCodeId !== null) {
                updateQRCodeFilePath($qrCodeId, $filePath);
            }
        } catch (\Throwable $e) {
            // ⚠️ File save failed - log but don't fail the generation
            if (function_exists('logError')) {
                logError('warning', 'QR code file save failed', $e->getMessage());
            }
        }
    }

    // ==================================================================
    // 📌 Step 11: Log activity
    // @see fn_activity_log.php - logActivity()
    // ==================================================================
    if (function_exists('logActivity')) {
        logActivity(
            'qr_generated',
            'Generated ' . strtoupper($format) . ' QR code for type: ' . $typeName,
            'qr_code',
            $qrCodeId,
            [
                'uuid'          => $uuid,
                'typeName'      => $typeName,
                'format'        => $format,
                'payloadLength' => strlen($payload),
                'hasLogo'       => !empty($customization['logo']),
                'hasFrame'      => ($frameStyle !== 'none'),
            ]
        );
    }

    // ✅ Generation successful
    $result['success'] = true;

    return $result;
}

// ==============================================================================
// 👁️ generatePreview() - Generate a quick preview QR code
// ==============================================================================

/**
 * 👁️ Generate a quick preview QR code image
 *
 * Creates a smaller, simpler QR code suitable for real-time preview
 * in the UI. The preview:
 *   - Is always PNG format
 *   - Is always 300px target size
 *   - Is not saved to disk
 *   - Is not recorded in the database
 *   - Still applies logo and frame customizations
 *
 * @param string $typeName      The QR code type
 * @param array  $inputData     Type-specific input data
 * @param array  $customization Visual customization options
 *
 * @return array Same structure as generateQRCode() but without uuid, filePath, or DB record
 *
 * @example
 *   $preview = generatePreview('url', ['url' => 'https://example.com'], [
 *       'fg_color' => '#333333',
 *       'pattern'  => 'round',
 *   ]);
 *
 *   if ($preview['success']) {
 *       echo '<img src="' . $preview['data'] . '" alt="Preview">';
 *   }
 */
function generatePreview(string $typeName, array $inputData, array $customization = []): array
{
    // 📌 Initialise the result array
    $result = [
        'success'  => false,
        'uuid'     => '',
        'data'     => '',
        'mimeType' => 'image/png',
        'filePath' => null,
        'payload'  => '',
        'error'    => null,
    ];

    // ==================================================================
    // 📌 Override customization for preview settings
    // ==================================================================
    $customization['format']    = 'png';
    $customization['size']      = 300;
    $customization['save_file'] = false;

    // ==================================================================
    // 📌 Build the payload
    // ==================================================================
    try {
        if (!function_exists('buildQRPayload')) {
            throw new \RuntimeException('Payload builder not available.');
        }

        $payload = buildQRPayload($typeName, $inputData);
        $result['payload'] = $payload;
    } catch (\Throwable $e) {
        $result['error'] = 'Preview payload build failed: ' . $e->getMessage();
        return $result;
    }

    // ==================================================================
    // 📌 Build QR options
    // ==================================================================
    try {
        if (!function_exists('buildQROptions')) {
            throw new \RuntimeException('Options builder not available.');
        }

        $customization['payload'] = $payload;

        // 📌 Upgrade ECC for logo previews
        if (!empty($customization['logo'])) {
            $customization['ecc'] = 'H';
        }

        $qrOptions = buildQROptions($customization);
    } catch (\Throwable $e) {
        $result['error'] = 'Preview options build failed: ' . $e->getMessage();
        return $result;
    }

    // ==================================================================
    // 📌 Generate the QR code
    // ==================================================================
    try {
        $qrcode   = new QRCode($qrOptions);
        $qrOutput = $qrcode->render($payload);
    } catch (\Throwable $e) {
        $result['error'] = 'Preview QR generation failed: ' . $e->getMessage();
        return $result;
    }

    // ==================================================================
    // 📌 Post-process: Logo overlay
    // ==================================================================
    if (!empty($customization['logo'])) {
        try {
            $logoPath = null;
            if (function_exists('getLogoPath')) {
                $logoPath = getLogoPath($customization['logo']);
            }

            if ($logoPath !== null && function_exists('overlayLogoOnRaster')) {
                if (isset($customization['logo_size_pct'])) {
                    $prevLogoSizePctVal = (int) $customization['logo_size_pct'];
                } else {
                    $prevLogoSizePctVal = 20;
                }

                if (isset($customization['logo_padding_shape'])) {
                    $prevLogoPadShapeVal = $customization['logo_padding_shape'];
                } else {
                    $prevLogoPadShapeVal = 'circle';
                }

                $logoConfig = [
                    'size_pct'      => $prevLogoSizePctVal,
                    'padding'       => true,
                    'padding_pct'   => 15,
                    'padding_shape' => $prevLogoPadShapeVal,
                    'output_format' => 'png',
                ];
                $qrOutput = overlayLogoOnRaster($qrOutput, $logoPath, $logoConfig);
            }
        } catch (\Throwable $e) {
            // ⚠️ Logo overlay failed in preview - continue without logo
        }
    }

    // ==================================================================
    // 📌 Post-process: Frame rendering
    // ==================================================================
    if (isset($customization['frame_style'])) {
        $prevFrameStyleValue = $customization['frame_style'];
    } elseif (isset($customization['frame'])) {
        $prevFrameStyleValue = $customization['frame'];
    } else {
        $prevFrameStyleValue = 'none';
    }
    $frameStyle = strtolower(trim($prevFrameStyleValue));

    if ($frameStyle !== 'none' && $frameStyle !== '') {
        try {
            if (function_exists('getFrameConfig') && function_exists('addFrameToRaster')) {
                $frameConfig = getFrameConfig($customization);
                if (isset($frameConfig['style'])) {
                    $prevFrameConfigStyle = $frameConfig['style'];
                } else {
                    $prevFrameConfigStyle = 'none';
                }
                if ($prevFrameConfigStyle !== 'none') {
                    $qrOutput = addFrameToRaster($qrOutput, $frameConfig, 'png');
                }
            }
        } catch (\Throwable $e) {
            // ⚠️ Frame rendering failed in preview - continue without frame
        }
    }

    // ==================================================================
    // 📌 Format output as base64 data URI
    // @see https://www.php.net/manual/en/function.base64-encode.php
    // ==================================================================
    $result['data']    = 'data:image/png;base64,' . base64_encode($qrOutput);
    $result['success'] = true;

    return $result;
}

// ==============================================================================
// 💾 saveQRToFile() - Save QR code image to filesystem
// ==============================================================================

/**
 * 💾 Save a QR code image to the _uploads/qrcodes/ directory
 *
 * Saves the QR code image data to a file named {uuid}.{extension}
 * in the application's uploads/qrcodes directory.
 *
 * @param string $imageData The raw image data (binary string or SVG markup)
 * @param string $uuid      The UUID v4 identifier for this QR code
 * @param string $format    The file extension (png, jpg, gif, webp, svg, pdf)
 *
 * @return string|null The absolute file path on success, or null on failure
 *
 * @see https://www.php.net/manual/en/function.file-put-contents.php
 * @see https://www.php.net/manual/en/function.is-dir.php
 * @see https://www.php.net/manual/en/function.mkdir.php
 *
 * @example
 *   $filePath = saveQRToFile($pngData, 'a1b2c3d4-e5f6-7890-abcd-ef1234567890', 'png');
 *   // Returns: '/var/www/CueRCode/_uploads/qrcodes/a1b2c3d4-e5f6-7890-abcd-ef1234567890.png'
 */
function saveQRToFile(string $imageData, string $uuid, string $format): ?string
{
    // 📌 Determine the uploads directory path
    // @see bootstrap.php - CUERCODE_UPLOADS_DIR constant
    $uploadsDir = '';

    if (defined('CUERCODE_UPLOADS_DIR')) {
        $uploadsDir = CUERCODE_UPLOADS_DIR . 'qrcodes' . DIRECTORY_SEPARATOR;
    } else {
        // 📌 Fallback: determine path relative to this file
        $uploadsDir = dirname(__DIR__) . DIRECTORY_SEPARATOR
            . '_uploads' . DIRECTORY_SEPARATOR
            . 'qrcodes' . DIRECTORY_SEPARATOR;
    }

    // 📌 Ensure the directory exists
    // @see https://www.php.net/manual/en/function.is-dir.php
    // @see https://www.php.net/manual/en/function.mkdir.php
    if (!is_dir($uploadsDir)) {
        $created = @mkdir($uploadsDir, 0755, true);
        if ($created === false) {
            if (function_exists('logError')) {
                logError('error', 'Failed to create QR codes upload directory', 'Path: ' . $uploadsDir);
            }
            return null;
        }
    }

    // 📌 Validate the format/extension
    $validExtensions = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'pdf'];
    $format = strtolower(trim($format));

    if (!in_array($format, $validExtensions, true)) {
        $format = 'png';
    }

    // 📌 Build the file path
    // Use the UUID as the filename for uniqueness and traceability.
    $fileName = $uuid . '.' . $format;
    $filePath = $uploadsDir . $fileName;

    // 📌 Write the image data to the file
    // @see https://www.php.net/manual/en/function.file-put-contents.php
    $bytesWritten = @file_put_contents($filePath, $imageData);

    if ($bytesWritten === false) {
        if (function_exists('logError')) {
            logError('error', 'Failed to write QR code file', 'Path: ' . $filePath);
        }
        return null;
    }

    // 📌 Set file permissions (readable by web server)
    // @see https://www.php.net/manual/en/function.chmod.php
    @chmod($filePath, 0644);

    return $filePath;
}

// ==============================================================================
// 🔑 generateUUIDv4() - Generate RFC 4122 UUID v4
// ==============================================================================

/**
 * 🔑 Generate a cryptographically secure RFC 4122 UUID v4
 *
 * Creates a random UUID (Universally Unique Identifier) version 4.
 * Uses random_bytes() for cryptographic randomness, then sets the
 * version and variant bits per RFC 4122 section 4.4.
 *
 * Format: xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
 * Where 'x' is any hex digit and 'y' is one of [8, 9, a, b].
 *
 * @return string The UUID v4 string (36 characters with hyphens)
 *
 * @see https://datatracker.ietf.org/doc/html/rfc4122#section-4.4
 * @see https://www.php.net/manual/en/function.random-bytes.php
 * @see https://www.php.net/manual/en/function.bin2hex.php
 * @see https://www.php.net/manual/en/function.sprintf.php
 *
 * @example
 *   $uuid = generateUUIDv4();
 *   // Returns: 'a1b2c3d4-e5f6-4890-abcd-ef1234567890'
 */
function generateUUIDv4(): string
{
    // 📌 Generate 16 random bytes (128 bits) using cryptographic PRNG
    // @see https://www.php.net/manual/en/function.random-bytes.php
    $bytes = random_bytes(16);

    // 📌 Set version to 4 (0100 in binary)
    // Byte 6: clear the top 4 bits, set to 0100 (version 4)
    // @see https://datatracker.ietf.org/doc/html/rfc4122#section-4.4
    $bytes[6] = chr(ord($bytes[6]) & 0x0f | 0x40);

    // 📌 Set variant to RFC 4122 (10xx in binary)
    // Byte 8: clear the top 2 bits, set to 10 (variant 1)
    // @see https://datatracker.ietf.org/doc/html/rfc4122#section-4.4
    $bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80);

    // 📌 Format as UUID string: xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
    // @see https://www.php.net/manual/en/function.bin2hex.php
    // @see https://www.php.net/manual/en/function.sprintf.php
    return sprintf(
        '%s-%s-%s-%s-%s',
        bin2hex(substr($bytes, 0, 4)),   // 8 hex chars (time-low)
        bin2hex(substr($bytes, 4, 2)),   // 4 hex chars (time-mid)
        bin2hex(substr($bytes, 6, 2)),   // 4 hex chars (time-high-and-version)
        bin2hex(substr($bytes, 8, 2)),   // 4 hex chars (clock-seq)
        bin2hex(substr($bytes, 10, 6))   // 12 hex chars (node)
    );
}

// ==============================================================================
// 🗄️ saveQRCodeRecord() - Save QR code record to database
// ==============================================================================

/**
 * 🗄️ Save a QR code record to the tblQRCodes database table
 *
 * Stores metadata about the generated QR code including the UUID,
 * type, payload, customization settings, and format.
 *
 * @param string $uuid          The UUID v4 identifier
 * @param string $typeName      The QR code type name
 * @param string $payload       The encoded payload data
 * @param array  $customization The customization options used
 * @param string $format        The output format
 *
 * @return int|null The inserted record ID, or null on failure
 *
 * @see https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php
 * @see https://www.php.net/manual/en/function.json-encode.php
 */
function saveQRCodeRecord(
    string $uuid,
    string $typeName,
    string $payload,
    array  $customization,
    string $format
): ?int {
    // ✅ Verify database function is available
    if (!function_exists('dbQuery')) {
        return null;
    }

    try {
        // 📌 Encode customization as JSON for storage
        // @see https://www.php.net/manual/en/function.json-encode.php
        $customizationJson = json_encode(
            $customization,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        // 📌 Get the user ID from session (if available)
        if (isset($_SESSION['userID'])) {
            $userId = $_SESSION['userID'];
        } else {
            $userId = null;
        }

        // 📌 Get the QR code type ID from tblQRCodeTypes
        $typeId = null;
        try {
            $typeStmt = dbQuery(
                "SELECT `typeID` FROM `tblQRCodeTypes` WHERE LOWER(`typeName`) = ? LIMIT 1",
                's',
                [strtolower($typeName)]
            );
            $typeResult = $typeStmt->get_result();
            $typeRow    = $typeResult->fetch_assoc();
            $typeStmt->close();

            if ($typeRow !== null) {
                $typeId = (int) $typeRow['typeID'];
            }
        } catch (\Throwable $e) {
            // ⚠️ Type lookup failed - continue with null typeID
        }

        // 📌 Insert the QR code record
        // @see https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php
        if ($userId !== null) {
            $userIdType = 'i';
        } else {
            $userIdType = 's';
        }

        // 📌 Column names aligned to the canonical install.sql schema:
        //    qrUUID, qrTypeID (FK), qrPayload, qrOptions (JSON), qrOutputFormat, userID.
        //    There is no typeName/qrFormat/customizationJSON column on tblQRCodes -
        //    the type name resolves to qrTypeID above and the customisation snapshot
        //    is stored in the qrOptions JSON column.
        $stmt = dbQuery(
            "INSERT INTO `tblQRCodes` ("
            . "`qrUUID`, `qrTypeID`, `qrPayload`, `qrOptions`, `qrOutputFormat`, "
            . "`userID`"
            . ") VALUES (?, ?, ?, ?, ?, ?)",
            'sisss' . $userIdType,
            [
                $uuid,
                $typeId,
                $payload,
                $customizationJson,
                $format,
                $userId,
            ]
        );

        // 📌 Get the inserted record ID
        // @see https://www.php.net/manual/en/mysqli.insert-id.php
        $insertId = $stmt->insert_id;
        $stmt->close();

        if ($insertId > 0) {
            return (int) $insertId;
        } else {
            return null;
        }
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError(
                'error',
                'Failed to save QR code record',
                'UUID: ' . $uuid . ' | ' . $e->getMessage()
            );
        }
        return null;
    }
}

// ==============================================================================
// 📝 updateQRCodeFilePath() - Update the file path for a QR code record
// ==============================================================================

/**
 * 📝 Update the file path column for an existing QR code record
 *
 * Called after the QR code image has been saved to disk, to record
 * the file path in the database for later retrieval and download.
 *
 * @param int    $qrCodeId The tblQRCodes record ID
 * @param string $filePath The absolute file path
 *
 * @return void
 *
 * @see https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php
 */
function updateQRCodeFilePath(int $qrCodeId, string $filePath): void
{
    if (!function_exists('dbQuery')) {
        return;
    }

    try {
        $stmt = dbQuery(
            "UPDATE `tblQRCodes` SET `qrFilePath` = ? WHERE `qrID` = ?",
            'si',
            [$filePath, $qrCodeId]
        );
        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError(
                'warning',
                'Failed to update QR code file path',
                'ID: ' . $qrCodeId . ' | ' . $e->getMessage()
            );
        }
    }
}
