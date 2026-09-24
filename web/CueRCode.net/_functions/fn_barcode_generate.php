<?php
/**
 * ==============================================================================
 * 📊 CueRCode - Non-QR Barcode Generation Engine
 * ==============================================================================
 *
 * Path: _functions/fn_barcode_generate.php
 *
 * Generates NON-QR barcode symbologies (both 1D "linear" and 2D "matrix"
 * codes) via the vendored tecnickcom/tc-lib-barcode library, alongside the
 * existing chillerlan/php-qrcode-powered QR pipeline (fn_qr_generate.php).
 * This file deliberately mirrors that pipeline's shape - a strict validate ->
 * render -> persist flow, catch-and-report errors rather than fatal, reuse of
 * the SAME tblQRCodes persistence helpers (saveQRCodeRecord(), saveQRToFile(),
 * updateQRCodeFilePath(), generateUUIDv4()) - so the saved-codes library,
 * download endpoint, and ownership/IDOR machinery all work unchanged for a
 * generated barcode exactly as they do for a QR code (#69).
 *
 * Supported symbologies (11 total; see CUERCODE_BARCODE_SYMBOLOGIES below):
 *   1D: code128, code39, ean13, ean8, upca, upce, itf, codabar
 *   2D: datamatrix, pdf417, aztec
 *
 * STRICT input validation (normalizeBarcodeData() and friends) runs BEFORE
 * the payload ever reaches the rendering library, mirroring
 * fn_qr_payloads.php's buildPayload_gs1_product() reject-early style:
 * EAN-13/EAN-8/UPC-A check digits are independently computed/verified with
 * the universal GTIN weighting algorithm (gtinCheckDigit()), UPC-E is
 * expanded to UPC-A for check-digit verification (upcEExpandToUpcA11(),
 * mirroring tc-lib-barcode's own UpcE::convertUpceToUpca() logic), Code39's
 * charset is enforced, and ITF requires an even-length numeric string. The
 * underlying library is lenient (it silently zero-pads/recomputes malformed
 * numeric input rather than rejecting it - verified empirically while
 * vendoring this library) so this validation layer is the ONLY thing
 * standing between a malformed request and a barcode that looks valid but
 * decodes to the wrong product/value.
 *
 * ⚠️ PDF417 + bcmath: tc-lib-barcode's PDF417 "numeric compaction" text mode
 * (triggered internally for long runs of consecutive digits) calls bcmath
 * functions (bcmod/bcdiv). Code128, Code39, EAN/UPC/ITF/Codabar, DataMatrix
 * and Aztec do NOT use bcmath anywhere in their encode path (verified by
 * reading the vendored source - the only bcmath call sites are
 * Type/Linear/Imb.php [IMB, not exposed here] and
 * Type/Square/PdfFourOneSeven/Compaction.php's numeric-compaction branch).
 * bcmath is a near-universal PHP extension and is expected to be present on
 * the production host, but if it is ever missing, a PDF417 request with a
 * long digit run fails CLOSED with a caught, logged, generic
 * "Barcode rendering failed" error (see renderBarcodeToOutput() below) rather
 * than a raw fatal - see DEV_NOTES.md for the deployment checklist note.
 *
 * @package     CueRCode
 * @subpackage  Barcode Generation
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2 (#69)
 * @see         fn_qr_generate.php   - generateQRCode()/generatePreview() (the QR pipeline this mirrors)
 * @see         fn_api_generate.php  - handleBarcodeGenerate()/handleBarcodePreview() (the HTTP layer)
 * @see         https://github.com/tecnickcom/tc-lib-barcode tc-lib-barcode documentation
 * @see         https://www.gs1.org/standards/barcodes GS1 barcode standards (EAN/UPC/ITF)
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
// 📋 CUERCODE_BARCODE_SYMBOLOGIES - the symbology registry
// ==============================================================================
// Single source of truth mapping our public API symbology key to:
//   - 'tclbType'  the tc-lib-barcode Barcode::BARCODETYPES type string
//   - 'category'  '1d' (linear) or '2d' (matrix) - drives scale/height/
//                 show_text/ecc option semantics below
//   - 'typeName'  the tblQRCodeTypes.typeName this symbology persists under
//                 (seeded rows, seed_data.sql) - reused by saveQRCodeRecord()
//   - 'label'     human-readable label (mirrors seed_data.sql's typeLabel,
//                 kept here too so validation error messages read naturally)
// ==============================================================================

/**
 * @var array<string, array{tclbType: string, category: string, typeName: string, label: string}>
 */
if (!defined('CUERCODE_BARCODE_SYMBOLOGIES')) {
    define('CUERCODE_BARCODE_SYMBOLOGIES', [
        'code128'    => ['tclbType' => 'C128',       'category' => '1d', 'typeName' => 'barcode_code128',    'label' => 'Code 128'],
        'code39'     => ['tclbType' => 'C39',        'category' => '1d', 'typeName' => 'barcode_code39',     'label' => 'Code 39'],
        'ean13'      => ['tclbType' => 'EAN13',      'category' => '1d', 'typeName' => 'barcode_ean13',      'label' => 'EAN-13'],
        'ean8'       => ['tclbType' => 'EAN8',       'category' => '1d', 'typeName' => 'barcode_ean8',       'label' => 'EAN-8'],
        'upca'       => ['tclbType' => 'UPCA',       'category' => '1d', 'typeName' => 'barcode_upca',       'label' => 'UPC-A'],
        'upce'       => ['tclbType' => 'UPCE',       'category' => '1d', 'typeName' => 'barcode_upce',       'label' => 'UPC-E'],
        'itf'        => ['tclbType' => 'I25',        'category' => '1d', 'typeName' => 'barcode_itf',        'label' => 'ITF (Interleaved 2 of 5)'],
        'codabar'    => ['tclbType' => 'CODABAR',    'category' => '1d', 'typeName' => 'barcode_codabar',    'label' => 'Codabar'],
        'datamatrix' => ['tclbType' => 'DATAMATRIX', 'category' => '2d', 'typeName' => 'barcode_datamatrix', 'label' => 'Data Matrix'],
        'pdf417'     => ['tclbType' => 'PDF417',     'category' => '2d', 'typeName' => 'barcode_pdf417',     'label' => 'PDF417'],
        'aztec'      => ['tclbType' => 'AZTEC',      'category' => '2d', 'typeName' => 'barcode_aztec',      'label' => 'Aztec Code'],
    ]);
}

/** @var int Maximum accepted length (bytes) of the raw 'data' field before any per-symbology check. */
if (!defined('CUERCODE_BARCODE_MAX_DATA_LEN')) {
    define('CUERCODE_BARCODE_MAX_DATA_LEN', 2048);
}

// ==============================================================================
// 📋 barcodeSymbologyList() - The public symbology list (for /types, docs)
// ==============================================================================

/**
 * 📋 Return the list of supported symbology keys.
 *
 * @return array<int, string>
 */
function barcodeSymbologyList(): array
{
    return array_keys(CUERCODE_BARCODE_SYMBOLOGIES);
}

// ==============================================================================
// 🔍 resolveBarcodeSymbology() - Look up a symbology's registry entry
// ==============================================================================

/**
 * 🔍 Resolve a symbology key to its registry entry.
 *
 * @param string $symbology The caller-supplied symbology key (case-insensitive)
 *
 * @return array{tclbType: string, category: string, typeName: string, label: string}
 *
 * @throws \InvalidArgumentException If the symbology is not recognised.
 */
function resolveBarcodeSymbology(string $symbology): array
{
    $key = strtolower(trim($symbology));

    if ($key === '' || !array_key_exists($key, CUERCODE_BARCODE_SYMBOLOGIES)) {
        throw new \InvalidArgumentException(
            'Unknown barcode symbology: "' . $symbology . '". Supported: '
            . implode(', ', barcodeSymbologyList()) . '.'
        );
    }

    return CUERCODE_BARCODE_SYMBOLOGIES[$key];
}

// ==============================================================================
// 🔢 gtinCheckDigit() - Universal GTIN (EAN/UPC/ITF-14) check-digit algorithm
// ==============================================================================

/**
 * 🔢 Compute the GS1 GTIN check digit for a digit-only payload.
 *
 * The SAME weighting algorithm produces the correct check digit for EAN-8
 * (7 data digits), UPC-A (11 data digits), EAN-13 (12 data digits) and
 * GTIN-14/ITF-14 (13 data digits): reverse the digits, then alternately
 * weight 3/1 starting from the rightmost (position 1) digit.
 *
 * @param string $digitsWithoutCheck A digit-only string (the payload WITHOUT
 *                                   its check digit).
 *
 * @return int The computed check digit (0-9).
 *
 * @see https://www.gs1.org/services/how-calculate-check-digit-manually
 */
function gtinCheckDigit(string $digitsWithoutCheck): int
{
    $reversed = strrev($digitsWithoutCheck);
    $length   = strlen($reversed);
    $sum      = 0;

    for ($position = 0; $position < $length; $position++) {
        // 📌 Weight alternates 3, 1, 3, 1, ... starting at the rightmost digit.
        if ($position % 2 === 0) {
            $weight = 3;
        } else {
            $weight = 1;
        }

        $sum += ((int) $reversed[$position]) * $weight;
    }

    return (10 - ($sum % 10)) % 10;
}

// ==============================================================================
// 🔢 normalizeGtinLikeData() - Shared EAN-13/EAN-8/UPC-A validator
// ==============================================================================

/**
 * 🔢 Validate and normalise a digit-only, GTIN-check-digit-style payload.
 *
 * Accepts EITHER the payload without its check digit (exactly
 * $dataDigitsLen digits - the check digit is computed and appended) OR the
 * full payload WITH its check digit (exactly $dataDigitsLen + 1 digits -
 * the supplied check digit is verified against the computed one and
 * REJECTED if it does not match). This is the strict-reject behaviour the
 * underlying library does NOT provide on its own (it silently zero-pads and
 * recomputes instead of rejecting malformed input - verified while
 * vendoring the library).
 *
 * @param string $data          The raw 'data' field value.
 * @param int    $dataDigitsLen The number of digits EXCLUDING the check digit
 *                               (11 for UPC-A, 12 for EAN-13, 7 for EAN-8).
 * @param string $label         Human-readable symbology label for error text.
 *
 * @return string The full payload INCLUDING a valid check digit.
 *
 * @throws \InvalidArgumentException If the data is not digit-only, is the
 *         wrong length, or carries an incorrect check digit.
 */
function normalizeGtinLikeData(string $data, int $dataDigitsLen, string $label): string
{
    $data = trim($data);

    if (preg_match('/^\d+$/', $data) !== 1) {
        throw new \InvalidArgumentException(
            $label . ' data must contain digits only (no spaces, dashes, or letters).'
        );
    }

    $length = strlen($data);

    if ($length === $dataDigitsLen) {
        // 📌 Check digit not supplied - compute and append it.
        $checkDigit = gtinCheckDigit($data);
        return $data . (string) $checkDigit;
    }

    if ($length === ($dataDigitsLen + 1)) {
        // 📌 Full payload supplied INCLUDING a check digit - verify it.
        $body           = substr($data, 0, $dataDigitsLen);
        $suppliedDigit  = (int) substr($data, -1);
        $expectedDigit  = gtinCheckDigit($body);

        if ($suppliedDigit !== $expectedDigit) {
            throw new \InvalidArgumentException(
                'Invalid ' . $label . ' check digit: expected ' . $expectedDigit
                . ', got ' . $suppliedDigit . ' (payload: "' . $data . '").'
            );
        }

        return $data;
    }

    throw new \InvalidArgumentException(
        $label . ' must be ' . $dataDigitsLen . ' digits (without a check digit) or '
        . ($dataDigitsLen + 1) . ' digits (including a valid check digit); got '
        . $length . ' digit(s).'
    );
}

// ==============================================================================
// 🔢 upcEExpandToUpcA11() - UPC-E compact code -> 11-digit UPC-A body
// ==============================================================================

/**
 * 🔢 Expand a 6-digit UPC-E compact code to its 11-digit UPC-A equivalent
 * (system digit '0' + 10 body digits, WITHOUT a check digit).
 *
 * Mirrors tc-lib-barcode's own Type\Linear\UpcE::convertUpceToUpca() logic
 * exactly (verified against the vendored source) so our independent
 * check-digit verification agrees with what the library itself will encode.
 *
 * @param string $compact6 Exactly 6 digits (the UPC-E compact code, no
 *                          system digit, no check digit).
 *
 * @return string The 11-digit UPC-A body (no check digit).
 *
 * @see https://en.wikipedia.org/wiki/Universal_Product_Code#UPC-E
 */
function upcEExpandToUpcA11(string $compact6): string
{
    $lastDigit = $compact6[5];

    if ($lastDigit < '3') {
        $body = $compact6[0] . $compact6[1] . $lastDigit . '0000' . $compact6[2] . $compact6[3] . $compact6[4];
        return '0' . $body;
    }

    if ($lastDigit === '3') {
        $body = $compact6[0] . $compact6[1] . $compact6[2] . '00000' . $compact6[3] . $compact6[4];
        return '0' . $body;
    }

    if ($lastDigit === '4') {
        $body = $compact6[0] . $compact6[1] . $compact6[2] . $compact6[3] . '00000' . $compact6[4];
        return '0' . $body;
    }

    $body = $compact6[0] . $compact6[1] . $compact6[2] . $compact6[3] . $compact6[4] . '0000' . $lastDigit;
    return '0' . $body;
}

// ==============================================================================
// ✅ normalizeBarcodeData() - Per-symbology STRICT validation + normalisation
// ==============================================================================

/**
 * ✅ Validate and normalise the raw 'data' field for a given symbology.
 *
 * This is the barcode analogue of fn_qr_payloads.php's buildPayload_*()
 * functions: strict, reject-early validation that mirrors
 * buildPayload_gs1_product()'s style (trim, type-check, regex/checksum
 * validate, throw \InvalidArgumentException with an actionable message on
 * ANY failure - never silently coerce bad input into "close enough").
 *
 * @param string $symbologyKey The already-resolved, lowercased symbology key
 *                              (a CUERCODE_BARCODE_SYMBOLOGIES key).
 * @param string $data         The raw 'data' field value.
 *
 * @return string The normalised value to hand to tc-lib-barcode's Barcode::getBarcodeObj().
 *
 * @throws \InvalidArgumentException If the data is invalid for this symbology.
 */
function normalizeBarcodeData(string $symbologyKey, string $data): string
{
    // 📏 A blanket length cap BEFORE any per-symbology work - stops a
    // pathologically large payload from reaching a validator/encoder.
    if (strlen($data) > CUERCODE_BARCODE_MAX_DATA_LEN) {
        throw new \InvalidArgumentException(
            'Barcode data is too long: ' . strlen($data) . ' bytes (maximum '
            . CUERCODE_BARCODE_MAX_DATA_LEN . ').'
        );
    }

    switch ($symbologyKey) {
        // ------------------------------------------------------------
        // 🔠 CODE 128 - full printable-ASCII range (0x20-0x7E), 1-80 chars.
        // The library auto-selects subsets A/B/C; we do not require the
        // caller to pick one.
        // ------------------------------------------------------------
        case 'code128':
            $value = trim($data);
            if ($value === '' || strlen($value) > 80) {
                throw new \InvalidArgumentException(
                    'Code 128 data must be 1-80 characters.'
                );
            }
            if (preg_match('/^[\x20-\x7E]+$/', $value) !== 1) {
                throw new \InvalidArgumentException(
                    'Code 128 data must contain only printable ASCII characters (0x20-0x7E).'
                );
            }
            return $value;

        // ------------------------------------------------------------
        // 🔠 CODE 39 - standard charset: 0-9, A-Z (uppercase only), space,
        // and the symbols - . $ / + %. 1-80 chars. No checksum required
        // (the base 'C39' type, not the checksum variant 'C39+').
        // ------------------------------------------------------------
        case 'code39':
            $value = trim($data);
            if (preg_match('/^[0-9A-Z \-.$\/+%]{1,80}$/', $value) !== 1) {
                throw new \InvalidArgumentException(
                    'Code 39 data must be 1-80 characters from the Code 39 charset '
                    . '(digits, UPPERCASE letters, space, and - . $ / + %). '
                    . 'Lowercase letters are not encodable in standard Code 39.'
                );
            }
            return $value;

        // ------------------------------------------------------------
        // 🏷️ EAN-13 - 12 digits (check digit computed) or 13 digits
        // (check digit verified).
        // ------------------------------------------------------------
        case 'ean13':
            return normalizeGtinLikeData($data, 12, 'EAN-13');

        // ------------------------------------------------------------
        // 🏷️ EAN-8 - 7 digits (check digit computed) or 8 digits
        // (check digit verified).
        // ------------------------------------------------------------
        case 'ean8':
            return normalizeGtinLikeData($data, 7, 'EAN-8');

        // ------------------------------------------------------------
        // 🏷️ UPC-A - 11 digits (check digit computed) or 12 digits
        // (check digit verified).
        // ------------------------------------------------------------
        case 'upca':
            return normalizeGtinLikeData($data, 11, 'UPC-A');

        // ------------------------------------------------------------
        // 🏷️ UPC-E - 6-digit compact code (no system digit, no check
        // digit - the form tc-lib-barcode's encoder itself consumes), OR
        // an 8-digit fully-labelled code (system digit '0' + 6-digit
        // compact code + check digit) with the check digit independently
        // verified via UPC-A expansion.
        // ------------------------------------------------------------
        case 'upce':
            $value = trim($data);

            if (preg_match('/^\d{6}$/', $value) === 1) {
                return $value;
            }

            if (preg_match('/^\d{8}$/', $value) === 1) {
                $numberSystemDigit = $value[0];

                if ($numberSystemDigit !== '0') {
                    throw new \InvalidArgumentException(
                        'UPC-E 8-digit form must start with number-system digit "0" '
                        . '(the only system digit this encoder supports).'
                    );
                }

                $compact       = substr($value, 1, 6);
                $suppliedDigit = (int) $value[7];
                $expectedDigit = gtinCheckDigit(upcEExpandToUpcA11($compact));

                if ($suppliedDigit !== $expectedDigit) {
                    throw new \InvalidArgumentException(
                        'Invalid UPC-E check digit: expected ' . $expectedDigit
                        . ', got ' . $suppliedDigit . ' (payload: "' . $value . '").'
                    );
                }

                return $compact;
            }

            throw new \InvalidArgumentException(
                'UPC-E data must be 6 digits (compact code) or 8 digits '
                . '(system digit + compact code + check digit); got '
                . strlen($value) . ' digit(s).'
            );

        // ------------------------------------------------------------
        // 📶 ITF (Interleaved 2 of 5) - digits only, EVEN length (a
        // structural requirement of interleaved 2-of-5 encoding, which
        // packs two digits per symbol), 2-40 digits.
        // ------------------------------------------------------------
        case 'itf':
            $value = trim($data);
            if (preg_match('/^\d{2,40}$/', $value) !== 1) {
                throw new \InvalidArgumentException(
                    'ITF data must be 2-40 digits (digits only).'
                );
            }
            if ((strlen($value) % 2) !== 0) {
                throw new \InvalidArgumentException(
                    'ITF (Interleaved 2 of 5) data must have an EVEN number of digits '
                    . '(got ' . strlen($value) . ') - pad with a leading zero if needed.'
                );
            }
            return $value;

        // ------------------------------------------------------------
        // 🔤 CODABAR - digits plus the symbols - $ : / . + , 1-40 chars.
        // Start/stop guard characters (A-D) are added automatically by the
        // rendering library when absent, so the caller supplies payload
        // digits/symbols only.
        // ------------------------------------------------------------
        case 'codabar':
            $value = trim($data);
            if (preg_match('/^[0-9\-$:\/.+]{1,40}$/', $value) !== 1) {
                throw new \InvalidArgumentException(
                    'Codabar data must be 1-40 characters from the Codabar charset '
                    . '(digits and the symbols - $ : / . +).'
                );
            }
            return $value;

        // ------------------------------------------------------------
        // 🟦 DATA MATRIX / PDF417 / AZTEC - general-purpose 2D symbologies.
        // Accept arbitrary printable text/binary-safe UTF-8 up to the
        // blanket length cap already enforced above; only reject empty
        // input and invalid UTF-8 (the payload is stored as-is in the
        // database, so it must be valid UTF-8 text).
        // ------------------------------------------------------------
        case 'datamatrix':
        case 'pdf417':
        case 'aztec':
            $value = trim($data);
            if ($value === '') {
                throw new \InvalidArgumentException(
                    CUERCODE_BARCODE_SYMBOLOGIES[$symbologyKey]['label'] . ' data must not be empty.'
                );
            }
            if (!mb_check_encoding($value, 'UTF-8')) {
                throw new \InvalidArgumentException(
                    CUERCODE_BARCODE_SYMBOLOGIES[$symbologyKey]['label'] . ' data must be valid UTF-8 text.'
                );
            }
            return $value;

        default:
            // 🛡️ Unreachable in practice (resolveBarcodeSymbology() already
            // rejects unknown keys before this function is ever called), but
            // kept as a fail-closed default rather than an unguarded fall-through.
            throw new \InvalidArgumentException('Unsupported barcode symbology: "' . $symbologyKey . '".');
    }
}

// ==============================================================================
// 🎨 renderBarcodeToOutput() - tc-lib-barcode render + optional HRI text
// ==============================================================================

/**
 * 🎨 Render a validated barcode payload to SVG or PNG bytes via tc-lib-barcode.
 *
 * @param string $symbologyKey    The resolved symbology key.
 * @param string $normalizedData  The ALREADY-VALIDATED payload (from normalizeBarcodeData()).
 * @param string $format          'svg' or 'png'.
 * @param array  $options         Sanitised options array:
 *   - 'scale'     (int) Module/bar width multiplier (1-10).
 *   - 'height'    (int) 1D: absolute bar height in px (10-300).
 *                       2D: row multiplier (1-10, default = scale).
 *   - 'show_text' (bool) Render the human-readable payload below the symbol
 *                        (1D symbologies only - a no-op for 2D).
 *   - 'ecc'       (int|null) PDF417 (0-8) / Aztec (1-100) error-correction
 *                        hint; ignored for every other symbology.
 *
 * @return array{output: string, mimeType: string}
 *
 * @throws \Throwable Any rendering failure from the underlying library (the
 *         caller - generateBarcode()/generateBarcodePreview() - catches this
 *         and reports it as a generic generation failure, mirroring
 *         fn_qr_generate.php's Step 4 QR-render try/catch).
 */
function renderBarcodeToOutput(string $symbologyKey, string $normalizedData, string $format, array $options): array
{
    $symbologyDef = CUERCODE_BARCODE_SYMBOLOGIES[$symbologyKey];

    // 📌 Options with defaults.
    if (isset($options['scale'])) {
        $scale = (int) $options['scale'];
    } else {
        $scale = 2;
    }
    $scale = max(1, min(10, $scale));

    if ($symbologyDef['category'] === '1d') {
        // 📏 1D: width is a NEGATIVE per-column multiplier; height is a
        // POSITIVE absolute pixel height.
        $width = -$scale;

        if (isset($options['height'])) {
            $heightOption = (int) $options['height'];
        } else {
            $heightOption = 50;
        }
        $height = max(10, min(300, $heightOption));
    } else {
        // 🟦 2D: both width and height are NEGATIVE per-module multipliers.
        // 'height' (if supplied) lets the caller request non-square modules
        // (mainly useful for PDF417 row height); default = scale (square).
        $width = -$scale;

        if (isset($options['height'])) {
            $rowScale = (int) $options['height'];
        } else {
            $rowScale = $scale;
        }
        $rowScale = max(1, min(10, $rowScale));
        $height   = -$rowScale;
    }

    // 📌 Build the tc-lib-barcode type string, appending symbology-specific
    // ECC parameters as comma-separated extras where applicable.
    $tclbType = $symbologyDef['tclbType'];

    if (isset($options['ecc']) && $options['ecc'] !== null) {
        $eccValue = (int) $options['ecc'];

        if ($symbologyKey === 'pdf417') {
            // 📌 PDF417 error-correction LEVEL is params[1] in the type
            // string ("PDF417,<aspect ratio>,<ecc level>"); leave the aspect
            // ratio slot empty so the library's own default applies.
            $eccValue = max(0, min(8, $eccValue));
            $tclbType = 'PDF417,,' . $eccValue;
        } elseif ($symbologyKey === 'aztec') {
            // 📌 Aztec error-correction PERCENTAGE is params[0]
            // ("AZTEC,<ecc percent>").
            $eccValue = max(1, min(100, $eccValue));
            $tclbType = 'AZTEC,' . $eccValue;
        }
        // 📌 DataMatrix uses a fixed ECC200 scheme (no configurable ECC) and
        // every 1D symbology has no ECC concept - 'ecc' is silently ignored
        // for those, matching how fn_api_generate.php documents pdf_layout
        // as "harmless/ignored for any other format".
    }

    // ==================================================================
    // 🖨️ Render via tc-lib-barcode
    // @see https://github.com/tecnickcom/tc-lib-barcode
    // ==================================================================
    $barcodeFactory = new \Com\Tecnick\Barcode\Barcode();
    $barcodeObj     = $barcodeFactory->getBarcodeObj($tclbType, $normalizedData, $width, $height, 'black');

    // 🎨 White (opaque) background - print-safe, matches the QR pipeline's
    // default white background convention rather than the library's own
    // default (a transparent background inferred from the foreground colour).
    $barcodeObj->setBackgroundColor('white');

    $showText = !empty($options['show_text']) && $symbologyDef['category'] === '1d';

    if ($format === 'svg') {
        $innerSvg = $barcodeObj->getInlineSvgCode();

        if ($showText) {
            $innerSvg = wrapBarcodeSvgWithText($innerSvg, $normalizedData);
        }

        $output = '<?xml version="1.0" standalone="no" ?>' . "\n" . $innerSvg;

        return ['output' => $output, 'mimeType' => 'image/svg+xml'];
    }

    // 📌 PNG - force the GD code path (imagick=false) so no additional PHP
    // extension is assumed beyond GD, which the house already depends on for
    // the QR/logo pipeline.
    $pngBytes = $barcodeObj->getPngData(false);

    if ($showText) {
        $pngBytes = appendBarcodeTextToPng($pngBytes, $normalizedData);
    }

    return ['output' => $pngBytes, 'mimeType' => 'image/png'];
}

// ==============================================================================
// ✏️ wrapBarcodeSvgWithText() - Append human-readable text under an SVG barcode
// ==============================================================================

/**
 * ✏️ Wrap an inner tc-lib-barcode SVG element in a taller outer SVG carrying
 * a centred `<text>` caption below it.
 *
 * SVG natively supports nesting one `<svg>` inside another; placing the
 * unmodified inner element at its default (0,0) position inside a taller
 * outer canvas avoids having to parse/rewrite the inner markup's own
 * geometry at all.
 *
 * @param string $innerSvg The inner `<svg ...>...</svg>` markup (NO XML
 *                          prolog - i.e. Model::getInlineSvgCode() output).
 * @param string $text     The human-readable text to render.
 *
 * @return string The wrapped outer `<svg ...>...</svg>` markup (still no
 *                XML prolog - the caller adds that once, at the very end).
 */
function wrapBarcodeSvgWithText(string $innerSvg, string $text): string
{
    // 📌 Extract the inner SVG's own declared width/height (tc-lib-barcode
    // always emits these as width="NNN.NNNNNN" / height="NNN.NNNNNN" -
    // @see Type::getInlineSvgCode()). Fall back to leaving the markup
    // untouched (fail-open, matching the logo-overlay/frame-rendering
    // convention elsewhere in this codebase) if the shape is unexpected.
    $widthMatched  = preg_match('/\bwidth="([0-9.]+)"/', $innerSvg, $widthMatches);
    $heightMatched = preg_match('/\bheight="([0-9.]+)"/', $innerSvg, $heightMatches);

    if ($widthMatched !== 1 || $heightMatched !== 1) {
        return $innerSvg;
    }

    $origWidth  = (float) $widthMatches[1];
    $origHeight = (float) $heightMatches[1];

    $textBandHeight = 24.0;
    $newHeight      = $origHeight + $textBandHeight;
    $textY          = $origHeight + ($textBandHeight * 0.7);
    $textX          = $origWidth / 2;

    // 🔒 Escape the caption for safe embedding as SVG text content.
    $escapedText = htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');

    return '<svg version="1.2" baseProfile="full" xmlns="http://www.w3.org/2000/svg" '
        . 'xmlns:xlink="http://www.w3.org/1999/xlink" width="' . $origWidth . '" '
        . 'height="' . $newHeight . '" viewBox="0 0 ' . $origWidth . ' ' . $newHeight . '">' . "\n"
        . $innerSvg . "\n"
        . '<text x="' . $textX . '" y="' . $textY . '" text-anchor="middle" '
        . 'font-family="monospace" font-size="16" fill="#000000">' . $escapedText . '</text>' . "\n"
        . '</svg>';
}

// ==============================================================================
// ✏️ appendBarcodeTextToPng() - Append human-readable text under a PNG barcode
// ==============================================================================

/**
 * ✏️ Append a centred, human-readable text caption below a rendered PNG barcode.
 *
 * Uses GD's built-in bitmap font (imagestring()) - dependency-free, no
 * TrueType font file required (unlike fn_qr_frames.php's frame-label
 * rendering, which prefers a TTF font when available; a barcode caption is a
 * small, secondary annotation where the built-in font is entirely adequate).
 *
 * @param string $pngBytes Raw PNG image bytes.
 * @param string $text     The human-readable text to render.
 *
 * @return string The new PNG image bytes (original + text band), or the
 *                UNMODIFIED input on any GD failure (fail-open - the barcode
 *                itself is still valid; the caption is a nice-to-have,
 *                mirroring fn_qr_generate.php's logo-overlay failure posture).
 *
 * @see https://www.php.net/manual/en/function.imagestring.php
 * @see https://www.php.net/manual/en/function.getimagesizefromstring.php
 */
function appendBarcodeTextToPng(string $pngBytes, string $text): string
{
    $imageInfo = @getimagesizefromstring($pngBytes);

    if ($imageInfo === false) {
        return $pngBytes;
    }

    $width  = (int) $imageInfo[0];
    $height = (int) $imageInfo[1];

    $sourceImage = @imagecreatefromstring($pngBytes);

    if ($sourceImage === false) {
        return $pngBytes;
    }

    $font           = 3; // 📌 Built-in GD bitmap font (1-5; 3 is a readable medium size)
    $textBandHeight = imagefontheight($font) + 8;

    $canvas = @imagecreatetruecolor($width, $height + $textBandHeight);

    if ($canvas === false) {
        // 🧹 No imagedestroy($sourceImage) here - PHP releases it
        // automatically once this function returns and nothing refers to it
        // any more. imagedestroy() has been a no-op since PHP 8.0 and PHP
        // 8.5 warns when it is called (#117) - do not add it back.
        return $pngBytes;
    }

    // 🎨 White opaque background for the whole new canvas (matches the
    // barcode's own white background set via setBackgroundColor('white')).
    $white = imagecolorallocate($canvas, 255, 255, 255);
    if ($white !== false) {
        imagefilledrectangle($canvas, 0, 0, $width, $height + $textBandHeight, $white);
    }

    imagecopy($canvas, $sourceImage, 0, 0, 0, 0, $width, $height);

    // 🧹 $sourceImage's pixels are already copied into $canvas above, so it
    // is not needed for the rest of this function. unset() drops this
    // variable's reference to it, letting PHP free that memory straight
    // away instead of waiting for the function to return - the same effect
    // imagedestroy() had before PHP 8.0, but without the "deprecated"
    // warning PHP 8.5 now prints for imagedestroy() (#117). Do not change
    // this back to imagedestroy($sourceImage).
    unset($sourceImage);

    $black = imagecolorallocate($canvas, 0, 0, 0);
    if ($black !== false) {
        $textWidth = imagefontwidth($font) * strlen($text);
        $textX     = max(0, (int) round(($width - $textWidth) / 2));
        $textY     = $height + 4;
        imagestring($canvas, $font, $textX, $textY, $text, $black);
    }

    ob_start();
    $pngWritten = imagepng($canvas);
    $outputBytes = ob_get_clean();

    // 🧹 No imagedestroy($canvas) here - PHP releases it automatically once
    // this function returns and nothing refers to it any more.
    // imagedestroy() has been a no-op since PHP 8.0 and PHP 8.5 warns when
    // it is called (#117) - do not add it back.

    if (!$pngWritten || $outputBytes === false) {
        return $pngBytes;
    }

    return $outputBytes;
}

// ==============================================================================
// 🎯 generateBarcode() - Main barcode generation function (persisted)
// ==============================================================================

/**
 * 🎯 Generate a complete barcode, persisting it exactly like generateQRCode().
 *
 * Mirrors fn_qr_generate.php's generateQRCode() shape closely: validate ->
 * render -> generate UUID -> format output -> persist to tblQRCodes (via the
 * SAME saveQRCodeRecord() helper, using this symbology's seeded
 * `barcode_<symbology>` typeName) -> optionally save to the filesystem ->
 * log activity -> return a result array.
 *
 * @param string $symbology The requested symbology (case-insensitive).
 * @param string $data      The raw barcode payload/content.
 * @param array  $options   Sanitised options - see renderBarcodeToOutput()
 *                          for the recognised keys, plus:
 *   - 'format'    (string) 'svg' or 'png' (default 'svg').
 *   - 'save_file' (bool)   Whether to persist to the filesystem (default true).
 *
 * @return array Result array (SAME shape as generateQRCode()):
 *   - 'success'  (bool)
 *   - 'uuid'     (string)
 *   - 'data'     (string)      Raw SVG markup, or a base64 PNG data URI.
 *   - 'mimeType' (string)
 *   - 'filePath' (string|null)
 *   - 'payload'  (string)      The normalised/validated barcode value encoded.
 *   - 'error'    (string|null)
 */
function generateBarcode(string $symbology, string $data, array $options = []): array
{
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
    // 📌 Step 1: Resolve symbology + STRICT data validation
    // ==================================================================
    try {
        $symbologyDef   = resolveBarcodeSymbology($symbology);
        $symbologyKey   = strtolower(trim($symbology));
        $normalizedData = normalizeBarcodeData($symbologyKey, $data);
        $result['payload'] = $normalizedData;
    } catch (\InvalidArgumentException $e) {
        $result['error'] = $e->getMessage();
        if (function_exists('logError')) {
            logError('warning', 'Barcode generation input validation failed', $e->getMessage());
        }
        return $result;
    }

    if (isset($options['format'])) {
        $formatValue = $options['format'];
    } else {
        $formatValue = 'svg';
    }
    $format = strtolower(trim((string) $formatValue));
    if ($format !== 'svg' && $format !== 'png') {
        $format = 'svg';
    }

    // ==================================================================
    // 📌 Step 2: Render via tc-lib-barcode
    // ==================================================================
    try {
        $rendered = renderBarcodeToOutput($symbologyKey, $normalizedData, $format, $options);
        $output   = $rendered['output'];
        $mimeType = $rendered['mimeType'];
    } catch (\Throwable $e) {
        $result['error'] = 'Barcode rendering failed: ' . $e->getMessage();
        if (function_exists('logError')) {
            logError('error', 'Barcode render failed', $e->getMessage(), [
                'symbology' => $symbologyKey,
                'format'    => $format,
            ]);
        }
        return $result;
    }

    $result['mimeType'] = $mimeType;

    // ==================================================================
    // 📌 Step 3: Generate UUID + format output data
    // ==================================================================
    $uuid = generateUUIDv4();
    $result['uuid'] = $uuid;

    if ($format === 'svg') {
        $result['data'] = $output;
    } else {
        $result['data'] = 'data:' . $mimeType . ';base64,' . base64_encode($output);
    }

    // ==================================================================
    // 📌 Step 4: Persist to tblQRCodes (reuses the QR pipeline's own
    // persistence helpers - saveQRCodeRecord() resolves qrTypeID from THIS
    // symbology's seeded 'barcode_<symbology>' typeName row, seed_data.sql).
    // @see fn_qr_generate.php - saveQRCodeRecord(), saveQRToFile(), updateQRCodeFilePath()
    // ==================================================================
    $customizationForStorage              = $options;
    $customizationForStorage['symbology'] = $symbologyKey;
    $customizationForStorage['format']    = $format;

    $qrCodeId = saveQRCodeRecord($uuid, $symbologyDef['typeName'], $normalizedData, $customizationForStorage, $format);

    if (isset($options['save_file'])) {
        $saveFile = (bool) $options['save_file'];
    } else {
        $saveFile = true;
    }

    if ($saveFile) {
        try {
            $filePath = saveQRToFile($output, $uuid, $format);
            $result['filePath'] = $filePath;

            if ($filePath !== null && $qrCodeId !== null) {
                updateQRCodeFilePath($qrCodeId, $filePath);
            }
        } catch (\Throwable $e) {
            if (function_exists('logError')) {
                logError('warning', 'Barcode file save failed', $e->getMessage());
            }
        }
    }

    // ==================================================================
    // 📌 Step 5: Log activity
    // ==================================================================
    if (function_exists('logActivity')) {
        logActivity(
            'barcode_generated',
            'Generated ' . strtoupper($format) . ' barcode for symbology: ' . $symbologyKey,
            'qr_code',
            $qrCodeId,
            [
                'uuid'      => $uuid,
                'symbology' => $symbologyKey,
                'format'    => $format,
            ]
        );
    }

    $result['success'] = true;

    return $result;
}

// ==============================================================================
// 👁️ generateBarcodePreview() - Quick, unsaved preview barcode
// ==============================================================================

/**
 * 👁️ Generate a quick preview barcode - NOT saved to the database or filesystem.
 *
 * Mirrors fn_qr_generate.php's generatePreview(): forces a small, fast
 * output (SVG - vector, so there is no "preview size" trade-off the way
 * raster PNG has) and skips DB/filesystem persistence entirely.
 *
 * @param string $symbology The requested symbology (case-insensitive).
 * @param string $data      The raw barcode payload/content.
 * @param array  $options   Same option keys as generateBarcode() (format/
 *                          save_file are overridden below regardless of
 *                          what the caller passed).
 *
 * @return array Same shape as generateBarcode(), minus a real uuid/filePath.
 */
function generateBarcodePreview(string $symbology, string $data, array $options = []): array
{
    $result = [
        'success'  => false,
        'uuid'     => '',
        'data'     => '',
        'mimeType' => '',
        'filePath' => null,
        'payload'  => '',
        'error'    => null,
    ];

    try {
        $symbologyKey   = strtolower(trim($symbology));
        resolveBarcodeSymbology($symbology);
        $normalizedData = normalizeBarcodeData($symbologyKey, $data);
        $result['payload'] = $normalizedData;
    } catch (\InvalidArgumentException $e) {
        $result['error'] = $e->getMessage();
        return $result;
    }

    // 📌 Preview always renders SVG (vector - no size/quality trade-off) and
    // is never persisted.
    $options['format']    = 'svg';
    $options['save_file'] = false;

    try {
        $rendered = renderBarcodeToOutput($symbologyKey, $normalizedData, 'svg', $options);
    } catch (\Throwable $e) {
        $result['error'] = 'Barcode preview rendering failed: ' . $e->getMessage();
        return $result;
    }

    $result['mimeType'] = $rendered['mimeType'];
    $result['data']     = $rendered['output'];
    $result['success']  = true;

    return $result;
}
