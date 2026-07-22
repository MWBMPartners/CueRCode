<?php
/**
 * ==============================================================================
 * 🏷️ CueRCode - Application Version & Metadata (Alpha Development Build)
 * ==============================================================================
 *
 * Path: public_html_dev_alpha/infoAppVer.php
 *
 * Defines the canonical $app metadata array for the CueRCode Alpha development
 * build: identity (ID/name/description), semantic version, development-channel
 * status, repository build provenance, vendor details, copyright, and
 * licensing.
 *
 * This is a channel-specific copy of the production
 * public_html/infoAppVer.php — it MUST stay structurally in sync with that
 * file (same keys, same CI-coupling contract) and is bumped independently by
 * .github/workflows/version-bump.yml on pushes to the `alpha` branch.
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
 *   - .github/workflows/deploy.yml injects build provenance (commit SHA, short
 *     SHA, date, URL) at deploy time by matching four `// @build-info: ...`
 *     trailing anchor comments on the Repo→Commit assignments below — NOT by
 *     matching the literal `null` value. The four anchor comments
 *     (`commit-sha-full`, `commit-sha-short`, `commit-date`, `commit-url`)
 *     must be preserved verbatim (here AND on the public_html /
 *     public_html_dev_beta copies) or the injection silently no-ops and ships
 *     `null` commit info.
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
$app["Application"]["Version"]["Number"] = "0.1.4";
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
// ⚙️ deploy.yml matches the `// @build-info: ...` anchor comments below (NOT
//    the literal value) — keep the anchor text intact even if this block is
//    reflowed or reindented.
$app["Application"]["Version"]["Repo"]["Commit"]["SHA"]["Full"]  = null; // @build-info: commit-sha-full
$app["Application"]["Version"]["Repo"]["Commit"]["SHA"]["Short"] = null; // @build-info: commit-sha-short
$app["Application"]["Version"]["Repo"]["Commit"]["Date"]         = null; // @build-info: commit-date
$app["Application"]["Version"]["Repo"]["Commit"]["URL"]          = null; // @build-info: commit-url

// ==============================================================================
// 🏢 Vendor
// ==============================================================================
$app["Application"]["Vendor"]["Name"]        = "MWservices";
$app["Application"]["Vendor"]["Website"]["URL"] = "https://www.MWservices.it";

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
