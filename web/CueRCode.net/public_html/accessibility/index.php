<?php
/**
 * ==============================================================================
 * ♿ CueRCode - Accessible & Tactile QR Printing Guide
 * ==============================================================================
 *
 * Path: public_html/accessibility/index.php
 *
 * A best-practice guidance page for printing accessible / tactile QR codes:
 * the swell-paper & embosser workflow, minimum print sizing (the 10:1 rule),
 * quiet-zone requirements, placement/height/lighting, and how the Braille
 * caption + tactile locator frame print layouts (E5 / #49) are meant to be
 * used together. Linked from the site footer.
 *
 * @package     CueRCode
 * @subpackage  Pages / Accessibility
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2 (E5 accessibility cycle, #49)
 * @see         PROJECT_ROADMAP.md - E5 "Accessibility QR systems"
 * @see         fn_qr_accessible_pdf.php - the pdf_layout implementation this page documents
 */

// ==============================================================================
// 🚀 Load Application Bootstrap
// ==============================================================================

require_once __DIR__ . '/../../_includes/bootstrap.php';

// ==============================================================================
// ⚙️ Page Variables
// ==============================================================================

if (function_exists('getConfig')) {
    $result = getConfig('site_name', 'CueRCode');
    if (isset($result)) {
        $siteName = $result;
    } else {
        $siteName = 'CueRCode';
    }
} else {
    $siteName = 'CueRCode';
}

// 📅 Dynamic copyright year for the footer notice on this page.
$currentYear = date('Y');

$pageTitle       = 'Accessible & Tactile QR Printing Guide';
$pageDescription = 'How to print accessible and tactile QR codes with ' . $siteName
    . ' - swell-paper and embosser workflow, minimum size (the 10:1 rule), quiet '
    . 'zone, placement/height/lighting, and the Braille-caption and tactile '
    . 'locator-frame print layouts.';
$bodyClass       = 'page-legal page-accessibility';
$pageCSS         = [];
$pageJS          = [];

// ==============================================================================
// 📄 Render Page
// ==============================================================================

require_once CUERCODE_INCLUDES_DIR . 'header.php';
?>

    <!-- ====================================================================
         ♿ Accessible & Tactile QR Printing Guide Content
         ==================================================================== -->
    <section class="py-5">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <h1 class="h2 fw-bold mb-4">
                        <i class="fa-solid fa-universal-access me-2" aria-hidden="true"></i>
                        Accessible &amp; Tactile QR Printing Guide
                    </h1>
                    <p class="text-body-secondary mb-4">
                        A practical guide for anyone printing QR codes that need to be found and
                        confidently scanned by blind, low-vision, or low-dexterity users - covering
                        sizing, placement, and how to use
                        <?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?>'s Braille-caption
                        and tactile-frame print layouts.
                    </p>

                    <div class="legal-content">

                        <!-- ============================================================
                             📑 Contents / on-page navigation
                             ============================================================ -->
                        <nav aria-label="Guide contents" class="mb-4">
                            <h2 class="h5 mt-4 mb-3">Contents</h2>
                            <ul>
                                <li><a href="#basics">1. The basics: what makes a QR code accessible</a></li>
                                <li><a href="#sizing">2. Minimum size &amp; the 10:1 scan-distance rule</a></li>
                                <li><a href="#quiet-zone">3. Quiet zone &amp; contrast</a></li>
                                <li><a href="#placement">4. Placement, height &amp; lighting</a></li>
                                <li><a href="#braille-layer">5. The Braille-caption print layout</a></li>
                                <li><a href="#tactile-layer">6. The tactile locator-frame layer</a></li>
                                <li><a href="#label-sheets">7. Avery label sheets (bulk printing)</a></li>
                                <li><a href="#workflow">8. Swell-paper &amp; embosser workflow</a></li>
                                <li><a href="#scope">9. What this does NOT cover</a></li>
                            </ul>
                        </nav>

                        <!-- ============================================================
                             1. Basics
                             ============================================================ -->
                        <h2 id="basics" class="h5 mt-4 mb-3">1. The Basics: What Makes a QR Code Accessible</h2>
                        <p>
                            A standard QR code is entirely visual - a blind or low-vision person has no
                            way to know a code is even present, let alone where it is or which way up it
                            faces. A phone's camera can decode the DATA once pointed at the code, but a
                            person still needs to <em>find</em> the code first. Accessible QR printing
                            addresses that "finding" problem with three complementary, independently
                            useful layers:
                        </p>
                        <ul>
                            <li>
                                <strong>A large-print, human-readable caption</strong> - so anyone with low
                                vision (or anyone at all) can read the destination without scanning.
                            </li>
                            <li>
                                <strong>A Grade-1 Braille caption</strong> - the same short label rendered
                                as raised dots, readable by touch.
                            </li>
                            <li>
                                <strong>A tactile locator frame</strong> - a raised border around the code
                                itself, so a blind user can locate the code by touch BEFORE reaching for
                                their phone to scan it.
                            </li>
                        </ul>
                        <p>
                            None of these layers replace the QR code's own scannability requirements
                            (Sections&nbsp;2-3 below) - they sit alongside a code that must still meet
                            ordinary print-quality standards.
                        </p>

                        <!-- ============================================================
                             2. Sizing / 10:1 rule
                             ============================================================ -->
                        <h2 id="sizing" class="h5 mt-4 mb-3">2. Minimum Size &amp; the 10:1 Scan-Distance Rule</h2>
                        <p>
                            Two sizing rules apply, and the larger of the two wins:
                        </p>
                        <ul>
                            <li>
                                <strong>Absolute floor: 2&times;2&nbsp;cm (20&times;20&nbsp;mm).</strong>
                                Never print a scannable QR code smaller than this, regardless of scan
                                distance - modules become too small for a typical phone camera to resolve
                                reliably.
                            </li>
                            <li>
                                <strong>The 10:1 rule:</strong> a code's printed width should be at least
                                one-tenth of the intended scanning distance. A code meant to be scanned
                                from 1&nbsp;m away should be printed at least 10&nbsp;cm wide; from
                                3&nbsp;m away, at least 30&nbsp;cm.
                            </li>
                        </ul>
                        <p>
                            The generator's <strong>Accessible QR</strong> panel (Options tab, on the
                            <a href="/">main generator page</a>) includes a built-in calculator: enter
                            your intended scan distance and it recommends a minimum print size using this
                            exact rule, alongside enforced high error-correction and an auto-minimised QR
                            version (larger, more scan-friendly modules at a given print size).
                        </p>

                        <!-- ============================================================
                             3. Quiet zone / contrast
                             ============================================================ -->
                        <h2 id="quiet-zone" class="h5 mt-4 mb-3">3. Quiet Zone &amp; Contrast</h2>
                        <p>
                            Per ISO/IEC&nbsp;18004, a QR code needs a "quiet zone" - a clear margin of at
                            least 4 modules on every side, free of any other printed content (text,
                            borders, logos, or the tactile frame described in Section&nbsp;6). Never disable
                            the quiet zone or crop tightly around the code, and keep the tactile frame's
                            margin (Section&nbsp;6) OUTSIDE the quiet zone, not overlapping it.
                        </p>
                        <p>
                            Foreground/background colour contrast matters for low-vision users even though
                            the phone camera does the actual decoding: aim for a WCAG contrast ratio of at
                            least 4.5:1 (the "Accessible QR" preset warns below this and rejects anything
                            under 3:1). Plain black-on-white remains the most reliable, highest-contrast
                            choice for any accessibility-focused code.
                        </p>

                        <!-- ============================================================
                             4. Placement / height / lighting
                             ============================================================ -->
                        <h2 id="placement" class="h5 mt-4 mb-3">4. Placement, Height &amp; Lighting</h2>
                        <ul>
                            <li>
                                <strong>Consistent height:</strong> in a series of signs/labels (e.g. a
                                museum trail, a row of shelf labels), place codes at the SAME height each
                                time so a returning visitor's hand or cane learns where to expect them.
                            </li>
                            <li>
                                <strong>Reachable, unobstructed placement:</strong> avoid glass cases,
                                sharp edges directly above/below the code, or placements that require
                                reaching over a hazard to touch the tactile frame.
                            </li>
                            <li>
                                <strong>Avoid glare and shadow:</strong> matte (non-glossy) print/laminate
                                finishes scan far more reliably under bright or directional lighting than
                                glossy finishes, which can wash the code out with reflected glare.
                            </li>
                            <li>
                                <strong>Flat, unwrapped surfaces:</strong> avoid printing over curved
                                surfaces, wrinkled shrink-wrap, or anything that visibly distorts the
                                module grid - distortion hurts both camera scanning and tactile reading.
                            </li>
                        </ul>

                        <!-- ============================================================
                             5. Braille-caption layer
                             ============================================================ -->
                        <h2 id="braille-layer" class="h5 mt-4 mb-3">5. The Braille-Caption Print Layout</h2>
                        <p>
                            Selecting <strong>PDF Print Layout: Accessible caption</strong> (Options tab,
                            format = PDF) generates a page containing the QR code, a large-print
                            (18pt+) human-readable caption below it, and a Grade-1 (uncontracted) Braille
                            transliteration of that same caption as a line of raised dots.
                        </p>
                        <p>
                            <strong>Grade-1 only:</strong> this covers the standard a-z alphabet, 0-9
                            digits (with the Braille number sign), a small common-punctuation set, and a
                            single-letter capital indicator. Grade-2 CONTRACTED Braille (whole-word
                            shorthand, e.g. a single cell standing in for "the") is intentionally out of
                            scope - it needs a large, locale-specific contraction dictionary that a short
                            caption line does not warrant. Any character outside this subset renders as a
                            blank cell rather than being silently mis-transliterated.
                        </p>
                        <p>
                            <strong>Dot geometry:</strong> dots are drawn at approximately
                            1.5&nbsp;mm diameter, on a 2.5&nbsp;mm intra-cell pitch, with a 6.0&nbsp;mm
                            cell-to-cell pitch and a 10&nbsp;mm line pitch - commonly-cited approximate
                            print/embossing figures ("Marburg Medium"-style spacing). Always PRINT OR
                            EMBOSS AT 100% SCALE - a "fit to page" or "shrink to fit" print setting will
                            distort the dot spacing below a comfortably readable size.
                        </p>

                        <!-- ============================================================
                             6. Tactile frame layer
                             ============================================================ -->
                        <h2 id="tactile-layer" class="h5 mt-4 mb-3">6. The Tactile Locator-Frame Layer</h2>
                        <p>
                            Selecting <strong>PDF Print Layout: Tactile frame</strong> generates
                            everything in Section&nbsp;5's caption layout PLUS a second, clearly-labelled
                            page containing <?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?>'s
                            own tactile locator frame design: a raised square border around the QR
                            code's position, four corner registration marks, and a single raised
                            "orientation" dot below the frame marking the bottom/start.
                        </p>
                        <p>
                            <strong>How to use it:</strong> the frame page is deliberately a SEPARATE page
                            from the caption page, sized and positioned to REGISTER exactly against it
                            (same paper size, same QR position, no scaling). Reproduce the frame as a
                            raised/tactile layer using one of:
                        </p>
                        <ul>
                            <li>
                                <strong>Swell paper</strong> (capsule paper) run through a tactile
                                image-enhancer/fuser - the black frame lines and dot puff up into raised
                                ridges.
                            </li>
                            <li>
                                <strong>A Braille embosser</strong> that supports graphics/tactile-graphics
                                mode, embossing the frame directly.
                            </li>
                            <li>
                                <strong>A UV-varnish or raised-ink print pass</strong> over the SAME sheet
                                as the caption page, if your printer/print shop offers spot-UV or raised
                                (thermographic) printing.
                            </li>
                        </ul>
                        <p>
                            A blind user runs their fingers along the frame's edge to locate the code by
                            touch, uses the corner marks to judge the code's extent, and the single bottom
                            dot to judge orientation - all BEFORE reaching for a phone to scan. This is an
                            original CueRCode frame design, not a reproduction of any third-party
                            patented tactile-locator design.
                        </p>

                        <!-- ============================================================
                             7. Avery label sheets
                             ============================================================ -->
                        <h2 id="label-sheets" class="h5 mt-4 mb-3">7. Avery Label Sheets (Bulk Printing)</h2>
                        <p>
                            Selecting <strong>PDF Print Layout: Avery 5160</strong> (US Letter, 3&times;10
                            labels/sheet) or <strong>Avery L7160</strong> (A4, 3&times;7 labels/sheet)
                            tiles the generated code onto a standard, commercially available address-label
                            sheet. Generating a SINGLE code fills exactly one label, leaving the rest of
                            the sheet blank as a reusable, printable template with light guide outlines
                            for every label position.
                        </p>
                        <p>
                            For BULK printing (many different codes, one per label), use the
                            <code>/api/v1/bulk-generate</code> endpoint with the same request-level
                            <code>format: "pdf"</code> + <code>pdf_layout</code> setting: every item in the
                            batch is tiled into successive labels, spanning as many sheet pages as needed.
                            See the <a href="/api/docs">API documentation</a> for the full request shape.
                        </p>
                        <p>
                            Always print label sheets at 100% scale and verify alignment against a
                            physical sheet before a large print run - published label templates are
                            manufacturer specifications, not a substitute for checking your own
                            printer/stock combination.
                        </p>

                        <!-- ============================================================
                             8. Swell-paper / embosser workflow
                             ============================================================ -->
                        <h2 id="workflow" class="h5 mt-4 mb-3">8. Swell-Paper &amp; Embosser Workflow (End-to-End)</h2>
                        <ol>
                            <li>Generate the code with the <strong>Accessible QR</strong> preset enabled (Section&nbsp;2-3 sizing/contrast rules applied automatically).</li>
                            <li>Choose PDF format with print layout <strong>Tactile frame</strong> (this includes the caption layout automatically).</li>
                            <li>Print (or emboss) BOTH pages at 100% scale, on the same physical sheet size.</li>
                            <li>Apply your chosen tactile process (swell paper, embosser, or UV-varnish pass) to the frame page, registered against the printed caption page.</li>
                            <li>Laminate with a MATTE finish if the sheet will be handled repeatedly or exposed to weather - avoid glossy laminate (Section&nbsp;4).</li>
                            <li>Mount at a consistent, reachable height, with the quiet zone and tactile frame both left completely unobstructed.</li>
                        </ol>

                        <!-- ============================================================
                             9. Scope / what this does not cover
                             ============================================================ -->
                        <h2 id="scope" class="h5 mt-4 mb-3">9. What This Does NOT Cover</h2>
                        <ul>
                            <li>
                                <strong>Grade-2 (contracted) Braille</strong> - see Section&nbsp;5. Use a
                                dedicated Braille translation tool if you need contracted output for a
                                longer document.
                            </li>
                            <li>
                                <strong>Proprietary colour-marker systems</strong> (e.g. NaviLens,
                                Zapvision/AQR) - these use patented, proprietary marker formats generated
                                only through their own commercial platforms; <?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?>
                                cannot generate them. Ordinary QR + screen-reader/VoiceOver/TalkBack camera
                                apps (which read a scanned URL's page content aloud) remain the
                                open, standards-based alternative.
                            </li>
                            <li>
                                <strong>Audio QR codes</strong> - server-side audio generation is not
                                offered; the destination page itself should be screen-reader-friendly so
                                the visited page (not the code) carries the accessible content.
                            </li>
                        </ul>

                        <p class="text-body-secondary mt-4">
                            &copy; <?php echo htmlspecialchars($currentYear, ENT_QUOTES, 'UTF-8'); ?>
                            <?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?>. All rights reserved.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>

<?php
require_once CUERCODE_INCLUDES_DIR . 'footer.php';
