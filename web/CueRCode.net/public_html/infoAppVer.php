<?php
/**
 * ==============================================================================
 * 🏷️ CueRCode - Application Version & Metadata
 * ==============================================================================
 *
 * Path: public_html/infoAppVer.php
 *
 * Defines the canonical $app metadata array for the CueRCode application:
 * identity (ID/name/description), semantic version, development-channel status,
 * repository build provenance, vendor details, copyright, and licensing.
 *
 * This file is intentionally a plain data-definition include (it produces no
 * output and registers no functions). Its values are read by the bootstrap and
 * surfaced in the UI/footer.
 *
 * ⚙️ CI coupling (do NOT reflow these lines without updating the workflows):
 *   - .github/workflows/version-bump.yml rewrites the semantic Version Number
 *     assignment via a case-sensitive sed; the literal single-space spacing
 *     around its equals sign must be kept, and the Number assignment must
 *     appear exactly once in the file (so the workflow's grep stays unambiguous).
 *   - .github/workflows/deploy.yml injects build provenance by matching the
 *     four Repo→Commit assignments against a literal single-space ` = NULL`
 *     (UPPERCASE) pattern at deploy time. Those four right-hand sides are
 *     therefore kept as the uppercase NULL token with single-space alignment on
 *     purpose; every other null below uses the house-standard lowercase null.
 *
 * @package     CueRCode
 * @subpackage  Configuration
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 1
 * @see         https://www.php.net/manual/en/language.types.array.php
 * @see         .github/workflows/version-bump.yml
 * @see         .github/workflows/deploy.yml
 */

// ==============================================================================
// 📦 Application identity
// ==============================================================================
$app["Application"]["ID"]   = "Ltd.MWBMPartners.CueRCode";
$app["Application"]["Name"] = "CueRCode";
$app["Application"]["Website"]["URL"] = null;
$app["Application"]["Description"]["Synopsis"] = "Free QR code generator tool. Create and customize QR codes for various purposes.";
$app["Application"]["Description"]["Keywords"] = "Free QR code generator tool. Create and customize QR codes for various purposes.";

// ==============================================================================
// 🔢 Version
// ==============================================================================
// ⚙️ The Number line below is rewritten by version-bump.yml — preserve the
//    exact `]["Number"] = "` spacing so the workflow's sed continues to match.
$app["Application"]["Version"]["Number"] = "0.1.1";
$app["Application"]["Version"]["Name"]   = null;

// 🌱 Environment-based development-channel override (failsafe)
// If running from a non-beta directory, clear the dev status accordingly.
if ((strpos(__DIR__, 'public_html_dev_alpha') !== false) || (strpos(__DIR__, 'public_html_alpha') !== false)) {
    $app["Application"]["Version"]["Development"]["Status"] = "Alpha";
} elseif ((strpos(__DIR__, 'public_html_dev_beta') !== false) || (strpos(__DIR__, 'public_html_beta') !== false)) {
    $app["Application"]["Version"]["Development"]["Status"] = "Beta";
} else {
    $app["Application"]["Version"]["Development"]["Status"] = null;
}

// 🏗️ Repo build provenance (populated by the GitHub Actions deploy workflow).
// ⚠️ deploy.yml matches the literal ` = NULL` (UPPERCASE) on these four lines to
//    inject the commit SHA/date/URL — keep them uppercase so injection works.
$app["Application"]["Version"]["Repo"]["Commit"]["SHA"]["Full"] = NULL;
$app["Application"]["Version"]["Repo"]["Commit"]["SHA"]["Short"] = NULL;
$app["Application"]["Version"]["Repo"]["Commit"]["Date"] = NULL;
$app["Application"]["Version"]["Repo"]["Commit"]["URL"] = NULL;

// ==============================================================================
// 🏢 Vendor
// ==============================================================================
$app["Application"]["Vendor"]["Name"]        = "MWservices";
$app["Application"]["Vendor"]["Website"]["URL"] = null;

// 🏛️ Parent vendor
$app["Application"]["Vendor"]["Parent"]["Name"]        = "MWBM Partners Ltd";
$app["Application"]["Vendor"]["Parent"]["Website"]["URL"] = "https://www.MWBMpartners.Ltd";

// ==============================================================================
// ©️ Copyright
// ==============================================================================
$app["Application"]["Copyright"]["Year"]["Start"]   = "2026";
$app["Application"]["Copyright"]["RightsStatement"] = "All Rights Reserved";

// ==============================================================================
// 📄 License
// ==============================================================================
// 🛠️ Developer licensing
$app["Application"]["License"]["Developer"]["Type"]             = null;
$app["Application"]["License"]["Developer"]["Cost"]             = null;
$app["Application"]["License"]["Developer"]["Agreement"]["URL"] = null;
$app["Application"]["License"]["Developer"]["ToSURL"]           = null;

// 👤 End-user licensing
$app["Application"]["License"]["User"]["Type"]             = "Freeware";
$app["Application"]["License"]["User"]["Cost"]             = "Free";
$app["Application"]["License"]["User"]["Agreement"]["URL"] = null;
$app["Application"]["License"]["User"]["ToSURL"]           = null;
