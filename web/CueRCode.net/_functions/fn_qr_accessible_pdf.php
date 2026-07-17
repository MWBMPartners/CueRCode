<?php
/**
 * ==============================================================================
 * ♿ CueRCode - Accessible/Tactile PDF Print Layouts (E5 / #49)
 * ==============================================================================
 *
 * Path: _functions/fn_qr_accessible_pdf.php
 *
 * Implements the "pdf_layout" customisation param (format=pdf only):
 *
 *   - 'standard'            (default) - a COMPLETE NO-OP. fn_qr_generate.php
 *                            never calls into this file for this value; the
 *                            existing chillerlan\QRCode\Output\QRFpdf byte
 *                            output is untouched. See fn_qr_generate.php -
 *                            the "Step 4a" block that gates on this file.
 *   - 'accessible_caption'  - the QR code + a large-print (>=18pt) human
 *                            readable caption + a Grade-1 (uncontracted)
 *                            Braille caption line, rendered as GEOMETRIC DOT
 *                            PATTERNS drawn directly with FPDF primitives
 *                            (Rect() strip-rasterised filled circles - core
 *                            FPDF ships no circle/ellipse operator and core
 *                            fonts have no Braille glyphs, so a Unicode
 *                            Braille font is not an option here).
 *   - 'tactile'             - 'accessible_caption' page 1, PLUS a second,
 *                            clearly-delineated page containing CueRCode's
 *                            OWN tactile locator frame design (registration
 *                            marks + orientation dot) meant to be overlaid
 *                            via swell paper / embosser / UV varnish. This is
 *                            NOT a reproduction of Chulalongkorn University's
 *                            Thai petty patent No. 17871 (Thailand-only, and
 *                            deliberately not cloned here regardless).
 *   - 'avery_5160'          - US Letter, 3x10 Avery address-label sheet
 *   - 'avery_l7160'         - A4, 3x7 Avery address-label sheet
 *                            (both tile the generated code(s) into label
 *                            cells; a single generateQRCode() call fills
 *                            exactly ONE label with the rest left as a
 *                            printable blank template; fn_api_bulk.php wires
 *                            multiple bulk items into successive label slots
 *                            across as many sheet pages as needed.)
 *
 * Braille Grade-1 (uncontracted) scope note: this file implements the a-z
 * letters, 0-9 digits (number-sign dots-3-4-5-6 prefix + the a-j letter
 * shapes), space, a small common-punctuation set, and a single-letter
 * capital indicator (dot 6). Grade-2 CONTRACTED Braille (whole-word/part-word
 * contractions, e.g. "the" -> a single cell) is explicitly OUT OF SCOPE -
 * it requires a large, locale-specific contraction dictionary and disambig-
 * uation logic far beyond a short QR caption's needs. Any character outside
 * this subset (accented letters, most symbols) renders as a blank cell
 * rather than being silently skipped, to keep caption <-> cell alignment
 * predictable and honest about the gap.
 *
 * Braille dot geometry: approximate "Marburg Medium" print/embossing figures
 * (dot base diameter ~1.5mm; intra-cell horizontal/vertical dot pitch
 * ~2.5mm; inter-cell/character pitch ~6.0mm; line pitch ~10mm). These are
 * widely-cited approximate figures for tactile/embossed print guidance, NOT
 * an embosser-certified spec - real hardware (BANA "standard"/"jumbo" cells
 * etc.) varies slightly. Print/emboss at 100% scale; do not "fit to page".
 *
 * ⚠️ IMPORTANT LOAD-ORDER NOTE: this file declares NO classes that extend
 *    \FPDF (drawing is done with a handful of free functions using only
 *    \FPDF's PUBLIC methods - Rect()/Line()/Cell()/MultiCell()/SetFont() etc.
 *    - core FPDF exposes no protected/internal access from outside the
 *    class, so a subclass was deliberately avoided). It DOES reference the
 *    global \FPDF class in type-hints and `new \FPDF(...)` calls, so the
 *    FPDF library (web/CueRCode.net/_libraries/fpdf/fpdf.php) must already be
 *    loaded before any function in this file that touches \FPDF is CALLED
 *    (not merely required - PHP resolves type-hints lazily at call time,
 *    unlike `class X extends Y`). In production this is guaranteed by
 *    bootstrap.php (autoload.php loads FPDF before any handler runs). Tests
 *    must call requireLibraryAutoloader() before exercising the \FPDF-touching
 *    functions - the pure Braille/geometry/param functions below need no
 *    such precondition.
 *
 * @package     CueRCode
 * @subpackage  QR Generation / Accessibility
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2 (E5 accessibility cycle, #49)
 * @see         https://www.fpdf.org/ FPDF Library (core fonts, primitives)
 * @see         https://en.wikipedia.org/wiki/Braille General Braille cell/dot background
 * @see         PROJECT_ROADMAP.md - E5 "Accessibility QR systems", DRAFT ISSUE (b)
 * @see         fn_qr_generate.php  - generateQRCode() Step 4a (wiring)
 * @see         fn_api_generate.php - buildCustomizationFromRequest() ('pdf_layout' param)
 * @see         fn_api_bulk.php     - handleBulkAverySheet(), bulkRequestWantsAveryLayout()
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
// @see https://www.php.net/manual/en/language.namespaces.importing.php
// ==============================================================================

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\Common\Mode;
use chillerlan\QRCode\Data\QRMatrix;

// ==============================================================================
// 📐 Braille geometry constants (millimetres) - approximate "Marburg Medium"
// print/embossing figures. @see file header for sourcing/caveats.
// ==============================================================================

if (!defined('CUERCODE_BRAILLE_DOT_DIAMETER_MM')) {
    define('CUERCODE_BRAILLE_DOT_DIAMETER_MM', 1.5);
}
if (!defined('CUERCODE_BRAILLE_DOT_PITCH_MM')) {
    define('CUERCODE_BRAILLE_DOT_PITCH_MM', 2.5);
}
if (!defined('CUERCODE_BRAILLE_CELL_PITCH_MM')) {
    define('CUERCODE_BRAILLE_CELL_PITCH_MM', 6.0);
}
if (!defined('CUERCODE_BRAILLE_LINE_PITCH_MM')) {
    define('CUERCODE_BRAILLE_LINE_PITCH_MM', 10.0);
}

// ==============================================================================
// ⠿ brailleGrade1LetterDots() - a-z dot patterns
// ==============================================================================

/**
 * ⠿ Return the Grade-1 (uncontracted) Braille dot pattern for each letter a-z.
 *
 * Dots are numbered per the universal 2x3 Braille cell convention:
 *   1 4
 *   2 5
 *   3 6
 *
 * @return array<string, int[]> Map of lowercase letter => sorted dot numbers.
 *
 * @see https://en.wikipedia.org/wiki/Braille - general cell/dot numbering background
 */
function brailleGrade1LetterDots(): array
{
    return [
        'a' => [1],
        'b' => [1, 2],
        'c' => [1, 4],
        'd' => [1, 4, 5],
        'e' => [1, 5],
        'f' => [1, 2, 4],
        'g' => [1, 2, 4, 5],
        'h' => [1, 2, 5],
        'i' => [2, 4],
        'j' => [2, 4, 5],
        'k' => [1, 3],
        'l' => [1, 2, 3],
        'm' => [1, 3, 4],
        'n' => [1, 3, 4, 5],
        'o' => [1, 3, 5],
        'p' => [1, 2, 3, 4],
        'q' => [1, 2, 3, 4, 5],
        'r' => [1, 2, 3, 5],
        's' => [2, 3, 4],
        't' => [2, 3, 4, 5],
        'u' => [1, 3, 6],
        'v' => [1, 2, 3, 6],
        'w' => [2, 4, 5, 6],
        'x' => [1, 3, 4, 6],
        'y' => [1, 3, 4, 5, 6],
        'z' => [1, 3, 5, 6],
    ];
}

// ==============================================================================
// ⠿ brailleGrade1DigitToLetter() - digit => a-j letter-shape equivalence
// ==============================================================================

/**
 * ⠿ Return the digit => letter-shape map used after a Braille number sign.
 *
 * Braille represents digits 1-9,0 using the SAME dot shapes as letters a-j
 * (1=a, 2=b, ... 9=i, 0=j), preceded by the number sign (dots 3-4-5-6).
 *
 * @return array<string, string> Map of digit character => equivalent a-j letter.
 */
function brailleGrade1DigitToLetter(): array
{
    return [
        '1' => 'a', '2' => 'b', '3' => 'c', '4' => 'd', '5' => 'e',
        '6' => 'f', '7' => 'g', '8' => 'h', '9' => 'i', '0' => 'j',
    ];
}

// ==============================================================================
// ⠿ brailleGrade1PunctuationDots() - a small common-punctuation subset
// ==============================================================================

/**
 * ⠿ Return dot patterns for a small, common English-Braille punctuation subset.
 *
 * Intentionally limited to the punctuation most likely to appear in a QR
 * caption (URLs, labels): period, comma, semicolon, colon, question mark,
 * exclamation mark, hyphen and apostrophe. Anything outside this subset (and
 * outside brailleGrade1LetterDots()/brailleGrade1DigitToLetter()) renders as
 * a blank cell - see brailleTransliterateGrade1().
 *
 * @return array<string, int[]> Map of punctuation character => dot numbers.
 */
function brailleGrade1PunctuationDots(): array
{
    return [
        '.'  => [2, 5, 6],
        ','  => [2],
        ';'  => [2, 3],
        ':'  => [2, 5],
        '?'  => [2, 3, 6],
        '!'  => [2, 3, 5],
        '-'  => [3, 6],
        "'"  => [3],
    ];
}

// ==============================================================================
// ⠿ brailleNumberSignDots() / brailleCapitalSignDots() - Braille indicators
// ==============================================================================

/**
 * ⠿ Return the Braille "number sign" dot pattern (dots 3-4-5-6).
 *
 * Precedes a run of digits; Grade-1 UEB convention. @see brailleTransliterateGrade1()
 *
 * @return int[] The number-sign dot numbers.
 */
function brailleNumberSignDots(): array
{
    return [3, 4, 5, 6];
}

/**
 * ⠿ Return the Braille "capital sign" dot pattern (dot 6 alone).
 *
 * Precedes a single capitalised letter. This file applies it letter-by-
 * letter (NOT the full UEB "capital passage" double-prefix optimisation for
 * runs of capitals) - a documented Grade-1 simplification appropriate for
 * short captions. @see brailleTransliterateGrade1()
 *
 * @return int[] The capital-sign dot numbers.
 */
function brailleCapitalSignDots(): array
{
    return [6];
}

// ==============================================================================
// ⠿ brailleTransliterateGrade1() - deterministic Grade-1 transliteration (PURE)
// ==============================================================================

/**
 * ⠿ Transliterate plain text into a list of Grade-1 Braille cells (PURE).
 *
 * Each returned "cell" is an int[] of dot numbers (1-6), sorted as defined
 * in the source tables, or an EMPTY array for a blank cell (space, or an
 * unsupported character - see the file header for scope). Indicator cells
 * (number sign, capital sign) are inserted as their own cells ahead of the
 * digit/letter they modify, exactly as real Braille does.
 *
 * Deterministic and side-effect-free: no I/O, no FPDF dependency, so this is
 * directly unit-testable (@see tests/test_braille.php).
 *
 * @param string $text The plain-text caption to transliterate.
 *
 * @return array<int, int[]> The list of cells, in reading order.
 *
 * @example
 *   brailleTransliterateGrade1('Hi 5');
 *   // => [ [6], [1,2,5], [2,4], [], [3,4,5,6], [1,5] ]
 *   //     cap   H        i     sp   #          5(=e)
 */
function brailleTransliterateGrade1(string $text): array
{
    $letters        = brailleGrade1LetterDots();
    $digitToLetter  = brailleGrade1DigitToLetter();
    $punctuation    = brailleGrade1PunctuationDots();

    $cells        = [];
    $inNumberMode = false;

    $length = mb_strlen($text);

    for ($charIndex = 0; $charIndex < $length; $charIndex++) {
        $char = mb_substr($text, $charIndex, 1);

        // 🟦 Space: blank cell, and any digit run in progress ends.
        if ($char === ' ') {
            $cells[]      = [];
            $inNumberMode = false;
            continue;
        }

        // 🔢 Digit: prefix a number sign the FIRST time we enter a digit run.
        if (ctype_digit($char)) {
            if (!$inNumberMode) {
                $cells[]      = brailleNumberSignDots();
                $inNumberMode = true;
            }
            $cells[] = $letters[$digitToLetter[$char]];
            continue;
        }

        // 📌 Any non-digit character ends a digit run (the next digit run
        //    gets its own fresh number sign).
        $inNumberMode = false;

        $lowerChar = mb_strtolower($char);

        // 🔠 Uppercase letter: capital-sign cell, then the letter's own shape.
        if ($char !== $lowerChar && isset($letters[$lowerChar])) {
            $cells[] = brailleCapitalSignDots();
            $cells[] = $letters[$lowerChar];
            continue;
        }

        // 🔡 Lowercase letter.
        if (isset($letters[$lowerChar])) {
            $cells[] = $letters[$lowerChar];
            continue;
        }

        // 🔣 Recognised punctuation.
        if (isset($punctuation[$char])) {
            $cells[] = $punctuation[$char];
            continue;
        }

        // ❓ Unsupported character (outside this Grade-1 subset - accented
        //    letters, most symbols, Grade-2 contractions are all out of
        //    scope). A blank cell keeps the cell count/position predictable
        //    rather than silently collapsing the caption.
        $cells[] = [];
    }

    return $cells;
}

// ==============================================================================
// 📐 brailleCellDotPositionsMm() - per-dot (x,y) offsets within a cell (PURE)
// ==============================================================================

/**
 * 📐 Compute the millimetre (x,y) position of each SET dot within one cell (PURE).
 *
 * Uses the standard 2x3 Braille cell numbering (1-4 top row, 2-5 middle row,
 * 3-6 bottom row) and the Marburg-Medium-approximate intra-cell dot pitch
 * (CUERCODE_BRAILLE_DOT_PITCH_MM).
 *
 * @param int[]  $dots         Dot numbers (1-6) to compute positions for.
 * @param float  $cellOriginXmm The cell's top-left X origin, in millimetres.
 * @param float  $cellOriginYmm The cell's top-left Y origin, in millimetres.
 *
 * @return array<int, array{0: float, 1: float}> List of [x, y] pairs (mm), one per dot.
 *
 * @example
 *   brailleCellDotPositionsMm([1, 5], 0.0, 0.0);
 *   // => [ [0.0, 0.0], [2.5, 2.5] ]   (dot 1 = top-left, dot 5 = mid-right)
 */
function brailleCellDotPositionsMm(array $dots, float $cellOriginXmm, float $cellOriginYmm): array
{
    $pitch = CUERCODE_BRAILLE_DOT_PITCH_MM;

    // 📌 Column (0=left dots 1/2/3, 1=right dots 4/5/6) and row (0=top,1=mid,2=bottom).
    $columnByDot = [1 => 0, 2 => 0, 3 => 0, 4 => 1, 5 => 1, 6 => 1];
    $rowByDot    = [1 => 0, 2 => 1, 3 => 2, 4 => 0, 5 => 1, 6 => 2];

    $positions = [];

    foreach ($dots as $dotNumber) {
        if (!isset($columnByDot[$dotNumber])) {
            // 🛡️ Defensive: silently ignore an out-of-range dot number rather
            //    than emit a malformed position (should never happen - all
            //    callers source dots from the tables above).
            continue;
        }

        $x = $cellOriginXmm + ($columnByDot[$dotNumber] * $pitch);
        $y = $cellOriginYmm + ($rowByDot[$dotNumber] * $pitch);

        $positions[] = [$x, $y];
    }

    return $positions;
}

// ==============================================================================
// 📐 brailleLineWidthMm() - total width spanned by N Braille cells (PURE)
// ==============================================================================

/**
 * 📐 Compute the total millimetre width spanned by a run of Braille cells (PURE).
 *
 * @param int $cellCount Number of cells in the run (0 or fewer returns 0.0).
 *
 * @return float The width in millimetres.
 */
function brailleLineWidthMm(int $cellCount): float
{
    if ($cellCount <= 0) {
        return 0.0;
    }

    // 📌 (N-1) inter-cell pitches between cells, plus one dot-pitch's worth
    //    of width for the trailing cell's own dot body.
    return (($cellCount - 1) * CUERCODE_BRAILLE_CELL_PITCH_MM) + CUERCODE_BRAILLE_DOT_PITCH_MM;
}

// ==============================================================================
// ⭕ circleStripHalfWidthMm() - pure circle-strip geometry helper (PURE)
// ==============================================================================

/**
 * ⭕ Compute a filled circle's half-width at a given vertical offset from centre (PURE).
 *
 * Implements the circle equation half-width = sqrt(r^2 - dy^2), the maths
 * behind drawFilledCircleOnPdf()'s horizontal-strip rasterisation (core FPDF
 * has no native circle/ellipse operator - only straight-edged Rect()/Line() -
 * so a filled "dot" is approximated as a small stack of full-width Rect()
 * strips whose width follows this formula).
 *
 * @param float $radiusMm                The circle's radius, in millimetres.
 * @param float $verticalOffsetFromCentreMm The vertical distance from the circle's
 *                                        centre to the strip's sample point, in mm.
 *
 * @return float The half-width in millimetres (0.0 if the offset is outside the circle).
 *
 * @example
 *   circleStripHalfWidthMm(1.0, 0.0);  // => 1.0 (through the centre, full radius)
 *   circleStripHalfWidthMm(1.0, 1.0);  // => 0.0 (exactly at the edge)
 *   circleStripHalfWidthMm(5.0, 3.0);  // => 4.0 (a 3-4-5 right triangle)
 */
function circleStripHalfWidthMm(float $radiusMm, float $verticalOffsetFromCentreMm): float
{
    $underRoot = ($radiusMm * $radiusMm) - ($verticalOffsetFromCentreMm * $verticalOffsetFromCentreMm);

    if ($underRoot <= 0.0) {
        return 0.0;
    }

    return sqrt($underRoot);
}

// ==============================================================================
// 📋 pdfLayoutOptions() / normalisePdfLayout() - the pdf_layout enum (PURE)
// ==============================================================================

/**
 * 📋 Return the full list of accepted 'pdf_layout' values.
 *
 * @return string[] The allowlisted pdf_layout values ('standard' first/default).
 */
function pdfLayoutOptions(): array
{
    return ['standard', 'accessible_caption', 'tactile', 'avery_5160', 'avery_l7160'];
}

/**
 * 📋 Normalise and validate a raw 'pdf_layout' value (PURE).
 *
 * Mirrors the inline allowlist logic in buildCustomizationFromRequest()
 * (fn_api_generate.php) - kept here too so callers INSIDE this file (and its
 * tests) have a single source of truth for the enum without introducing a
 * cross-file call at a point in the request lifecycle where this file may
 * not yet be loaded (@see fn_api_generate.php - buildCustomizationFromRequest()
 * for why that function keeps its OWN inline copy of this allowlist).
 *
 * @param mixed $rawValue The raw value (any type - only a matching string passes).
 *
 * @return string One of pdfLayoutOptions(); 'standard' for anything else.
 */
function normalisePdfLayout(mixed $rawValue): string
{
    if (!is_string($rawValue)) {
        return 'standard';
    }

    $normalised = strtolower(trim($rawValue));

    if (in_array($normalised, pdfLayoutOptions(), true)) {
        return $normalised;
    }

    return 'standard';
}

// ==============================================================================
// 📐 averySheetSpec() - Avery label-sheet geometry (PURE)
// ==============================================================================

/**
 * 📐 Return the physical geometry (millimetres) for a supported Avery sheet (PURE).
 *
 * Figures are the manufacturer-published template dimensions for the two
 * supported SKUs, rounded to a sensible precision:
 *   - avery_5160  - US Letter, 3 columns x 10 rows = 30 labels/sheet,
 *                   label 2.625in x 1in (66.675mm x 25.4mm).
 *   - avery_l7160 - A4, 3 columns x 7 rows = 21 labels/sheet,
 *                   label 63.5mm x 38.1mm.
 * Any other value falls back to avery_5160's geometry (defensive default -
 * callers are expected to have already validated the layout name via
 * normalisePdfLayout()).
 *
 * ⚠️ These are widely-published template figures, not a live measurement of
 *    a physical sheet in hand - verify alignment on your own printer/stock
 *    before a high-volume print run (see the guidance page, /accessibility).
 *
 * @param string $pdfLayout 'avery_5160' or 'avery_l7160'.
 *
 * @return array{
 *   page_width_mm: float, page_height_mm: float,
 *   columns: int, rows: int,
 *   label_width_mm: float, label_height_mm: float,
 *   margin_left_mm: float, margin_top_mm: float,
 *   column_pitch_mm: float, row_pitch_mm: float
 * } The sheet geometry.
 */
function averySheetSpec(string $pdfLayout): array
{
    if ($pdfLayout === 'avery_l7160') {
        return [
            'page_width_mm'   => 210.0,
            'page_height_mm'  => 297.0,
            'columns'         => 3,
            'rows'            => 7,
            'label_width_mm'  => 63.5,
            'label_height_mm' => 38.1,
            'margin_left_mm'  => 7.2,
            'margin_top_mm'   => 15.1,
            'column_pitch_mm' => 66.0,
            'row_pitch_mm'    => 38.1,
        ];
    }

    // 📌 Default: avery_5160 (US Letter).
    return [
        'page_width_mm'   => 215.9,
        'page_height_mm'  => 279.4,
        'columns'         => 3,
        'rows'            => 10,
        'label_width_mm'  => 66.675,
        'label_height_mm' => 25.4,
        'margin_left_mm'  => 4.7625,
        'margin_top_mm'   => 12.7,
        'column_pitch_mm' => 69.85,
        'row_pitch_mm'    => 25.4,
    ];
}

// ==============================================================================
// ✂️ accessiblePdfTruncateCaption() / accessiblePdfLatin1() - text helpers (PURE)
// ==============================================================================

/**
 * ✂️ Truncate a caption to a maximum length, appending an ellipsis (PURE).
 *
 * Bounds the size of the large-print + Braille caption rendering (a very
 * long payload - e.g. a huge URL - would otherwise produce an impractically
 * long Braille run). Multi-byte safe.
 *
 * @param string $text      The candidate caption text.
 * @param int    $maxLength Maximum character length before truncation (default 140).
 *
 * @return string The (possibly truncated) caption.
 */
function accessiblePdfTruncateCaption(string $text, int $maxLength = 140): string
{
    if (mb_strlen($text) <= $maxLength) {
        return $text;
    }

    return mb_substr($text, 0, max(0, $maxLength - 1)) . '…';
}

/**
 * ✂️ Convert a UTF-8 string to the single-byte encoding FPDF core fonts expect.
 *
 * FPDF's core fonts (Helvetica/Times/Courier) are single-byte WinAnsi
 * (roughly CP1252) fonts - Cell()/MultiCell()/Text() index character widths
 * BYTE-by-byte, so passing raw UTF-8 (multi-byte) text miscounts widths and
 * renders mojibake for anything outside ASCII. This is a KNOWN, DOCUMENTED
 * limitation of core (non-embedded) FPDF fonts - full Unicode would require
 * embedding a TrueType font, which is out of scope for this feature. Runs a
 * best-effort transliteration (accented Latin -> ASCII-ish, e.g. é -> e)
 * rather than dropping/mangling the string outright.
 *
 * @param string $utf8Text The UTF-8 input text.
 *
 * @return string The best-effort CP1252/Latin-1 single-byte equivalent.
 */
function accessiblePdfLatin1(string $utf8Text): string
{
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $utf8Text);
        if ($converted !== false) {
            return $converted;
        }
    }

    // 📌 Fallback if iconv is unavailable: ISO-8859-1 covers a large chunk of
    //    the same Latin-script range as CP1252.
    return mb_convert_encoding($utf8Text, 'ISO-8859-1', 'UTF-8');
}

// ==============================================================================
// 📝 deriveAccessiblePdfCaptionText() - pick a human-readable caption (PURE)
// ==============================================================================

/**
 * 📝 Derive the human-readable caption text for an accessible PDF layout (PURE).
 *
 * No new API param is introduced for this - the caption is derived
 * automatically from the type-specific input data (preferring a natural-
 * language field such as 'url'/'text'/'label'), falling back to the first
 * scalar input value, and finally to the raw encoded payload itself.
 * Truncated via accessiblePdfTruncateCaption() to keep the print layout
 * bounded.
 *
 * @param string $typeName  The QR code type (unused directly, kept for future
 *                          type-specific caption rules and for parity with
 *                          buildQRPayload()'s signature).
 * @param array  $inputData The type-specific input data (e.g. ['url' => '...']).
 * @param string $payload   The raw encoded QR payload (last-resort fallback).
 *
 * @return string The derived (and truncated) caption text.
 */
function deriveAccessiblePdfCaptionText(string $typeName, array $inputData, string $payload): string
{
    // 📌 $typeName is intentionally unused today (see docblock) - referencing
    //    it here keeps static-analysis tools quiet about an "unused param"
    //    without changing the public signature future type-specific rules
    //    may need.
    unset($typeName);

    $preferredKeys = ['url', 'text', 'label', 'phone', 'email'];

    foreach ($preferredKeys as $preferredKey) {
        if (isset($inputData[$preferredKey])
            && is_string($inputData[$preferredKey])
            && trim($inputData[$preferredKey]) !== ''
        ) {
            return accessiblePdfTruncateCaption(trim($inputData[$preferredKey]));
        }
    }

    // 📌 Fall back to the first scalar value present in the input data.
    foreach ($inputData as $inputValue) {
        if (is_string($inputValue) && trim($inputValue) !== '') {
            return accessiblePdfTruncateCaption(trim($inputValue));
        }
        if (is_scalar($inputValue)) {
            return accessiblePdfTruncateCaption((string) $inputValue);
        }
    }

    // 📌 Last resort: the raw encoded payload itself.
    return accessiblePdfTruncateCaption($payload);
}

// ==============================================================================
// 🎨 accessiblePdfResolveDarkRgb() - foreground colour for the print layout
// ==============================================================================

/**
 * 🎨 Resolve the [r,g,b] dark-module/text/dot colour from customisation.
 *
 * Delegates to hexToRgb() (fn_qr_options.php) when available, defaulting to
 * black - matching buildQROptions()'s own '#000000' fg_color default.
 *
 * @param array $customization The sanitised customisation array.
 *
 * @return int[] [r, g, b], each 0-255.
 */
function accessiblePdfResolveDarkRgb(array $customization): array
{
    if (isset($customization['fg_color']) && $customization['fg_color'] !== '') {
        $fgColor = (string) $customization['fg_color'];
    } else {
        $fgColor = '#000000';
    }

    if (function_exists('hexToRgb')) {
        return hexToRgb($fgColor);
    }

    // 🛡️ Defensive fallback if fn_qr_options.php was somehow not loaded.
    return [0, 0, 0];
}

// ==============================================================================
// 🧮 buildQrMatrixForAccessiblePdf() - obtain the raw QR module matrix
// ==============================================================================

/**
 * 🧮 Build a chillerlan QRMatrix for a payload WITHOUT going through an
 *     output class (QRFpdf, QRGdImagePNG, QRMarkupSVG, etc).
 *
 * The accessible/tactile/Avery layouts draw the QR modules themselves
 * (alongside captions/frames/label grids), so they need the raw dark/light
 * module grid - NOT a pre-rendered image. chillerlan\QRCode\QRCode::render()
 * always drives a full output class; QRCode::getQRMatrix() returns the
 * matrix directly but needs a data segment added first. This function
 * replicates render()'s own (public-API-only) segment-detection loop -
 * @see chillerlan\QRCode\QRCode::render() for the logic being mirrored -
 * using ONLY Mode::INTERFACES + addSegment() (both public), so it stays
 * forward-compatible with the library's public contract rather than reaching
 * into anything protected/internal.
 *
 * @param \chillerlan\QRCode\QROptions $qrOptions The options built by
 *                                                  buildQROptions() (fn_qr_options.php).
 * @param string $payload The encoded QR payload string.
 *
 * @return \chillerlan\QRCode\Data\QRMatrix The matrix (already quiet-zoned /
 *                                          logo-spaced per $qrOptions, exactly
 *                                          as QRCode::render() would produce).
 *
 * @see https://github.com/chillerlan/php-qrcode
 */
function buildQrMatrixForAccessiblePdf(QROptions $qrOptions, string $payload): QRMatrix
{
    $qrcode = new QRCode($qrOptions);

    foreach (Mode::INTERFACES as $dataModeClass) {
        if ($dataModeClass::validateString($payload)) {
            $qrcode->addSegment(new $dataModeClass($payload));
            break;
        }
    }

    return $qrcode->getQRMatrix();
}

// ==============================================================================
// 🖊️ drawFilledCircleOnPdf() - a small "filled dot" helper (strip-rasterised)
// ==============================================================================

/**
 * 🖊️ Draw a solid-filled circle (a Braille "dot") using only \FPDF::Rect().
 *
 * Core FPDF ships no native circle/ellipse operator - only straight-edged
 * Rect()/Line(). This approximates a filled circle as a small stack of
 * full-width horizontal Rect() strips whose half-width follows the circle
 * equation (@see circleStripHalfWidthMm()). At the small sizes a Braille dot
 * actually needs (~1.5mm diameter), a handful of strips reads as a clean
 * round dot on any real printer/embosser - this deliberately avoids
 * reaching into \FPDF's protected internals (e.g. the low-level PDF
 * content-stream operator method), which would require subclassing \FPDF
 * and its own load-order precondition (@see the file header).
 *
 * The caller is responsible for SetFillColor() beforehand (mirrors how
 * \FPDF::Rect(..., 'F') itself works).
 *
 * @param \FPDF $pdf         The FPDF document instance (must already have an
 *                           active page - i.e. AddPage() already called).
 * @param float $centreXmm   Circle centre X, in the document's user unit (mm).
 * @param float $centreYmm   Circle centre Y, in the document's user unit (mm).
 * @param float $radiusMm    Circle radius, in the document's user unit (mm).
 * @param int   $strips      Number of horizontal strips to rasterise with (default 10).
 *
 * @return void
 */
function drawFilledCircleOnPdf(\FPDF $pdf, float $centreXmm, float $centreYmm, float $radiusMm, int $strips = 10): void
{
    if ($radiusMm <= 0.0 || $strips < 2) {
        return;
    }

    $stripHeightMm = (2 * $radiusMm) / $strips;

    for ($stripIndex = 0; $stripIndex < $strips; $stripIndex++) {
        $stripTopYmm = $centreYmm - $radiusMm + ($stripIndex * $stripHeightMm);

        // 📌 Sample the circle equation at THIS strip's vertical midpoint
        //    (relative to the circle's centre) so strips above/below centre
        //    are symmetric.
        $stripMidOffsetFromCentreMm = ($stripTopYmm + ($stripHeightMm / 2)) - $centreYmm;

        $halfWidthMm = circleStripHalfWidthMm($radiusMm, $stripMidOffsetFromCentreMm);

        if ($halfWidthMm <= 0.0) {
            continue;
        }

        $pdf->Rect(
            $centreXmm - $halfWidthMm,
            $stripTopYmm,
            $halfWidthMm * 2,
            $stripHeightMm,
            'F'
        );
    }
}

// ==============================================================================
// 🖊️ drawQrModulesOnPdf() - render a boolean QR matrix onto an FPDF page
// ==============================================================================

/**
 * 🖊️ Draw a boolean QR module matrix onto an \FPDF page as filled squares.
 *
 * @param \FPDF     $pdf         The FPDF document instance (active page required).
 * @param bool[][]  $boolMatrix  The matrix from QRMatrix::getMatrix(true) (true = dark).
 * @param float     $originXmm   Top-left X of the QR's bounding box, in mm.
 * @param float     $originYmm   Top-left Y of the QR's bounding box, in mm.
 * @param float     $sizeMm      Total width/height the QR should occupy, in mm.
 * @param int[]     $darkRgb     [r, g, b] for dark modules.
 * @param int[]|null $lightRgb   [r, g, b] for light modules, or null to leave
 *                               the page's existing background showing through
 *                               (no rectangle drawn for light modules).
 *
 * @return void
 */
function drawQrModulesOnPdf(
    \FPDF $pdf,
    array $boolMatrix,
    float $originXmm,
    float $originYmm,
    float $sizeMm,
    array $darkRgb,
    ?array $lightRgb = null
): void {
    $moduleCount = count($boolMatrix);

    if ($moduleCount === 0) {
        return;
    }

    $moduleSizeMm = $sizeMm / $moduleCount;

    if ($lightRgb !== null) {
        $pdf->SetFillColor($lightRgb[0], $lightRgb[1], $lightRgb[2]);
        $pdf->Rect($originXmm, $originYmm, $sizeMm, $sizeMm, 'F');
    }

    $pdf->SetFillColor($darkRgb[0], $darkRgb[1], $darkRgb[2]);

    foreach ($boolMatrix as $rowIndex => $row) {
        foreach ($row as $colIndex => $isDark) {
            if (!$isDark) {
                continue;
            }

            $x = $originXmm + ($colIndex * $moduleSizeMm);
            $y = $originYmm + ($rowIndex * $moduleSizeMm);

            $pdf->Rect($x, $y, $moduleSizeMm, $moduleSizeMm, 'F');
        }
    }
}

// ==============================================================================
// 🖊️ drawBrailleLineOnPdf() - render a run of Braille cells, wrapping as needed
// ==============================================================================

/**
 * 🖊️ Draw a run of Braille cells (from brailleTransliterateGrade1()) onto a page.
 *
 * Wraps to additional lines when the run would exceed $maxWidthMm, using
 * CUERCODE_BRAILLE_CELL_PITCH_MM per cell and CUERCODE_BRAILLE_LINE_PITCH_MM
 * per line.
 *
 * @param \FPDF  $pdf        The FPDF document instance (active page required).
 * @param array  $cells      The cell list from brailleTransliterateGrade1().
 * @param float  $originXmm  Left edge X for the first cell, in mm.
 * @param float  $originYmm  Top edge Y for the first line, in mm.
 * @param float  $maxWidthMm Maximum line width before wrapping, in mm.
 * @param int[]  $dotRgb     [r, g, b] fill colour for the dots.
 *
 * @return float The Y position (mm) immediately below the LAST braille line
 *               drawn, for chaining subsequent layout.
 */
function drawBrailleLineOnPdf(
    \FPDF $pdf,
    array $cells,
    float $originXmm,
    float $originYmm,
    float $maxWidthMm,
    array $dotRgb
): float {
    $cellPitch = CUERCODE_BRAILLE_CELL_PITCH_MM;
    $linePitch = CUERCODE_BRAILLE_LINE_PITCH_MM;
    $dotRadius = CUERCODE_BRAILLE_DOT_DIAMETER_MM / 2;

    $pdf->SetFillColor($dotRgb[0], $dotRgb[1], $dotRgb[2]);

    $maxCellsPerLine = max(1, (int) floor($maxWidthMm / $cellPitch));

    $currentXmm  = $originXmm;
    $currentYmm  = $originYmm;
    $cellsOnLine = 0;

    foreach ($cells as $dots) {
        if ($cellsOnLine >= $maxCellsPerLine) {
            $currentXmm  = $originXmm;
            $currentYmm += $linePitch;
            $cellsOnLine = 0;
        }

        if (!empty($dots)) {
            $dotPositions = brailleCellDotPositionsMm($dots, $currentXmm, $currentYmm);
            foreach ($dotPositions as $dotPosition) {
                drawFilledCircleOnPdf($pdf, $dotPosition[0], $dotPosition[1], $dotRadius);
            }
        }

        $currentXmm += $cellPitch;
        $cellsOnLine++;
    }

    return $currentYmm + $linePitch;
}

// ==============================================================================
// 🖼️ drawTactileLocatorFrame() - CueRCode's own tactile locator frame design
// ==============================================================================

/**
 * 🖼️ Draw CueRCode's own tactile locator frame around a QR code's position.
 *
 * Design (deliberately original - NOT a reproduction of Chulalongkorn
 * University's Thai petty patent No. 17871, which is Thailand-only and not
 * cloned here regardless):
 *   - A thick continuous square frame offset a fixed margin outside the QR's
 *     bounding box (meant to read as a raised ridge once embossed / swell-
 *     printed / UV-varnished).
 *   - Four short corner "L" registration marks protruding OUTWARD from each
 *     frame corner - a distinct find-the-corner tactile cue separate from
 *     the continuous edge, and a visual/embosser alignment aid.
 *   - One raised orientation dot centred below the frame's bottom edge - a
 *     single unambiguous "this is the bottom / start here" tactile
 *     reference, distinct from the four (symmetric) corners.
 *
 * @param \FPDF $pdf       The FPDF document instance (active page required).
 * @param float $qrXmm     The QR's top-left X (must match the position used
 *                         for the QR this frame overlays - typically the
 *                         SAME position as the 'accessible_caption' page so
 *                         the two pages register against each other).
 * @param float $qrYmm     The QR's top-left Y.
 * @param float $qrSizeMm  The QR's width/height.
 * @param int[] $frameRgb  [r, g, b] colour for the frame/marks/dot.
 *
 * @return float The Y position (mm) immediately below the orientation dot,
 *               so callers can place any following content (e.g. usage
 *               instructions) without overlapping the frame/dot or having
 *               to duplicate this function's internal geometry constants.
 */
function drawTactileLocatorFrame(\FPDF $pdf, float $qrXmm, float $qrYmm, float $qrSizeMm, array $frameRgb): float
{
    $frameMarginMm            = 10.0; // gap between the QR's outer edge and the frame's inner edge
    $frameThicknessMm         = 3.0;  // frame line thickness (thick - meant to read as raised/tactile)
    $cornerMarkLengthMm       = 8.0;  // length of each corner registration "L" mark leg
    $orientationDotDiameterMm = 5.0;  // bottom orientation dot ("this side up / start here")

    $frameXmm    = $qrXmm - $frameMarginMm;
    $frameYmm    = $qrYmm - $frameMarginMm;
    $frameSizeMm = $qrSizeMm + (2 * $frameMarginMm);

    // 🖼️ The continuous outer frame.
    $pdf->SetDrawColor($frameRgb[0], $frameRgb[1], $frameRgb[2]);
    $pdf->SetLineWidth($frameThicknessMm);
    $pdf->Rect($frameXmm, $frameYmm, $frameSizeMm, $frameSizeMm, 'D');

    // 📐 Four corner "L" registration marks, protruding OUTWARD from each corner.
    $pdf->SetLineWidth(1.5);

    $corners = [
        [$frameXmm,                  $frameYmm,                  -1, -1], // top-left
        [$frameXmm + $frameSizeMm,   $frameYmm,                   1, -1], // top-right
        [$frameXmm,                  $frameYmm + $frameSizeMm,   -1,  1], // bottom-left
        [$frameXmm + $frameSizeMm,   $frameYmm + $frameSizeMm,    1,  1], // bottom-right
    ];

    foreach ($corners as $corner) {
        [$cornerXmm, $cornerYmm, $outwardXDirection, $outwardYDirection] = $corner;

        $pdf->Line($cornerXmm, $cornerYmm, $cornerXmm + ($outwardXDirection * $cornerMarkLengthMm), $cornerYmm);
        $pdf->Line($cornerXmm, $cornerYmm, $cornerXmm, $cornerYmm + ($outwardYDirection * $cornerMarkLengthMm));
    }

    // 🔵 The single bottom orientation dot.
    $pdf->SetFillColor($frameRgb[0], $frameRgb[1], $frameRgb[2]);
    $dotCentreXmm = $frameXmm + ($frameSizeMm / 2);
    $dotCentreYmm = $frameYmm + $frameSizeMm + ($frameMarginMm / 2);
    drawFilledCircleOnPdf($pdf, $dotCentreXmm, $dotCentreYmm, $orientationDotDiameterMm / 2);

    // 📌 Report the Y position just clear of the dot, so callers can place
    //    follow-up content (e.g. usage instructions) below the whole frame
    //    without needing to know/duplicate the constants above.
    return $dotCentreYmm + ($orientationDotDiameterMm / 2) + 5.0;
}

// ==============================================================================
// 📄 renderAveryLabelSheetPdf() - Avery label-sheet PDF (single or bulk)
// ==============================================================================

/**
 * 📄 Render one or more QR codes tiled into an Avery label-sheet PDF.
 *
 * Always renders at least one FULL sheet (even for a single item), so the
 * output is a usable printable template: every label cell gets a light grey
 * guide outline (helps verify alignment before committing to physical
 * die-cut stock); filled cells additionally get the QR code centred with a
 * small internal margin. When more items are supplied than fit on one
 * sheet, additional pages (sheets) are appended until every item has a slot.
 *
 * @param array<int, array{matrix: bool[][], moduleCount: int, darkRgb: int[]}> $items
 *   One entry per QR code to place, in placement order.
 * @param string $pdfLayout 'avery_5160' or 'avery_l7160' (@see averySheetSpec()).
 *
 * @return string The raw PDF bytes.
 */
function renderAveryLabelSheetPdf(array $items, string $pdfLayout): string
{
    $spec = averySheetSpec($pdfLayout);

    $pdf = new \FPDF('P', 'mm', [$spec['page_width_mm'], $spec['page_height_mm']]);
    $pdf->SetAutoPageBreak(false);
    $pdf->SetMargins(0, 0, 0);
    $pdf->SetTitle('CueRCode - Avery label sheet');

    $perSheet      = $spec['columns'] * $spec['rows'];
    $labelMarginMm = 2.0; // 📌 inner padding so the QR never touches the die-cut edge

    $itemCount = count($items);

    // 📌 Always fill at least one full sheet's worth of slots (even for a
    //    single item), so the output is a complete, usable template.
    $totalSlots  = max($perSheet, $itemCount);
    $pagesNeeded = max(1, (int) ceil($totalSlots / $perSheet));

    $itemIndex = 0;

    for ($pageNumber = 0; $pageNumber < $pagesNeeded; $pageNumber++) {
        $pdf->AddPage();

        for ($slotIndex = 0; $slotIndex < $perSheet; $slotIndex++) {
            $column = $slotIndex % $spec['columns'];
            $row    = intdiv($slotIndex, $spec['columns']);

            $cellXmm = $spec['margin_left_mm'] + ($column * $spec['column_pitch_mm']);
            $cellYmm = $spec['margin_top_mm'] + ($row * $spec['row_pitch_mm']);

            // 🖊️ Light guide outline for every label cell - a visual alignment
            //    aid when checking the printout against physical Avery stock.
            $pdf->SetDrawColor(200, 200, 200);
            $pdf->SetLineWidth(0.1);
            $pdf->Rect($cellXmm, $cellYmm, $spec['label_width_mm'], $spec['label_height_mm'], 'D');

            if ($itemIndex >= $itemCount) {
                continue; // 🈳 this slot stays blank (template only)
            }

            $item = $items[$itemIndex];
            $itemIndex++;

            $availableWidthMm  = $spec['label_width_mm'] - (2 * $labelMarginMm);
            $availableHeightMm = $spec['label_height_mm'] - (2 * $labelMarginMm);
            $qrSizeMm          = min($availableWidthMm, $availableHeightMm);

            $qrXmm = $cellXmm + (($spec['label_width_mm'] - $qrSizeMm) / 2);
            $qrYmm = $cellYmm + (($spec['label_height_mm'] - $qrSizeMm) / 2);

            drawQrModulesOnPdf($pdf, $item['matrix'], $qrXmm, $qrYmm, $qrSizeMm, $item['darkRgb']);
        }
    }

    return $pdf->Output('S');
}

// ==============================================================================
// 📄 renderAccessiblePdfLayout() - single-code accessible/tactile/Avery PDF
// ==============================================================================

/**
 * 📄 Render a single QR code's non-'standard' accessible PDF layout.
 *
 * Dispatches on $pdfLayout:
 *   - 'accessible_caption' / 'tactile' - a bespoke composite page (QR +
 *     large-print caption + Braille caption line), with 'tactile' adding a
 *     second, separately-identifiable page containing the tactile locator
 *     frame overlay guide.
 *   - 'avery_5160' / 'avery_l7160' - delegates to renderAveryLabelSheetPdf()
 *     with a single item (fills exactly one label on an otherwise-blank sheet).
 *
 * Never called for 'standard' - fn_qr_generate.php gates that value out
 * before reaching this file entirely (@see the file header).
 *
 * @param string     $pdfLayout    One of pdfLayoutOptions() EXCLUDING 'standard'.
 * @param string     $payload      The encoded QR payload string.
 * @param string     $captionText  The human-readable caption (@see deriveAccessiblePdfCaptionText()).
 * @param QROptions  $qrOptions    The options built by buildQROptions() (fn_qr_options.php).
 * @param array      $customization The sanitised customisation array (used for fg_color).
 *
 * @return string The raw PDF bytes.
 */
function renderAccessiblePdfLayout(
    string $pdfLayout,
    string $payload,
    string $captionText,
    QROptions $qrOptions,
    array $customization
): string {
    $matrix     = buildQrMatrixForAccessiblePdf($qrOptions, $payload);
    $boolMatrix = $matrix->getMatrix(true);
    $darkRgb    = accessiblePdfResolveDarkRgb($customization);

    // ==============================================================
    // 📄 Avery layouts: delegate to the label-sheet renderer with ONE item.
    // ==============================================================
    if ($pdfLayout === 'avery_5160' || $pdfLayout === 'avery_l7160') {
        return renderAveryLabelSheetPdf(
            [[
                'matrix'      => $boolMatrix,
                'moduleCount' => $matrix->getSize(),
                'darkRgb'     => $darkRgb,
            ]],
            $pdfLayout
        );
    }

    // ==============================================================
    // 📄 accessible_caption / tactile: bespoke composite A4 page(s).
    // ==============================================================
    $pdf = new \FPDF('P', 'mm', 'A4');
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetTitle('CueRCode - Accessible QR print layout');
    $pdf->AddPage();

    $pageWidthMm    = 210.0;
    $contentWidthMm = $pageWidthMm - 30.0; // 15mm margins each side

    $qrSizeMm = 70.0; // 📌 comfortably print-legible default; well above the 20mm ISO/IEC 18004 floor
    $qrXmm    = 15.0 + (($contentWidthMm - $qrSizeMm) / 2);
    $qrYmm    = 20.0;

    drawQrModulesOnPdf($pdf, $boolMatrix, $qrXmm, $qrYmm, $qrSizeMm, $darkRgb, [255, 255, 255]);

    // 📝 Large-print caption (>=18pt per the issue's requirement - 20pt used here).
    $pdf->SetXY(15.0, $qrYmm + $qrSizeMm + 8.0);
    $pdf->SetFont('Helvetica', 'B', 20);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->MultiCell($contentWidthMm, 10, accessiblePdfLatin1($captionText), 0, 'C');

    // ⠿ Braille caption line(s), clearly labelled.
    $pdf->SetFont('Helvetica', '', 10);
    $pdf->SetXY(15.0, $pdf->GetY() + 6.0);
    $pdf->Cell($contentWidthMm, 6, accessiblePdfLatin1('Grade-1 (uncontracted) Braille caption:'), 0, 1, 'L');

    $brailleCells = brailleTransliterateGrade1(mb_strtolower($captionText));
    drawBrailleLineOnPdf($pdf, $brailleCells, 15.0, $pdf->GetY() + 2.0, $contentWidthMm, [0, 0, 0]);

    if ($pdfLayout === 'tactile') {
        // 🖼️ Tactile locator frame - its OWN, clearly-delineated page, using
        //    the SAME QR position/size as page 1 so a physical swell-paper/
        //    embosser/UV-varnish pass registers correctly against page 1.
        //    A short title sits ABOVE the frame (it must fit before the
        //    frame's top edge, since the frame's Y is pinned to page 1's QR
        //    position); the full instructions are placed BELOW the frame and
        //    orientation dot, using the Y that drawTactileLocatorFrame()
        //    reports back, so there is no risk of overlapping the frame.
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->SetXY(15.0, 3.0);
        $pdf->Cell($contentWidthMm, 5, accessiblePdfLatin1('Tactile Locator Frame - Overlay Guide'), 0, 1, 'C');

        $frameBottomYmm = drawTactileLocatorFrame($pdf, $qrXmm, $qrYmm, $qrSizeMm, [0, 0, 0]);

        $pdf->SetFont('Helvetica', '', 10);
        $pdf->SetXY(15.0, $frameBottomYmm + 6.0);
        $pdf->MultiCell(
            $contentWidthMm,
            5,
            accessiblePdfLatin1(
                'Print or emboss this page ALIGNED with page 1 (same paper size, no '
                . '"fit to page" scaling). The frame above helps a blind or low-vision '
                . 'user locate the QR code by touch BEFORE scanning it - reproduce it on '
                . 'swell paper, via a braille embosser, or as a UV-varnish/raised-ink pass '
                . 'over the QR position on the SAME physical sheet as page 1. This is '
                . 'CueRCode\'s own frame design, not a reproduction of any third-party '
                . 'patented locator design.'
            ),
            0,
            'L'
        );
    }

    return $pdf->Output('S');
}
