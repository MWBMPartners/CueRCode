<?php
/**
 * ==============================================================================
 * 💷 CueRCode - Public Pricing / Plan-Comparison Page (Epic E4, #56)
 * ==============================================================================
 *
 * Path: public_html/pricing/index.php
 *
 * A plain, server-rendered plan-comparison page - one card per publicly
 * offered subscription tier, sourced entirely from tblSubscriptionPlans via
 * fn_pricing.php's listPublicPlansForDisplay()/pricingDisplayRowsFromPlans().
 * This is the page fn_features.php's resolveUpgradeUrl() points a denied
 * caller at by default (the 'billing_upgrade_url' setting seeds '/pricing').
 *
 * No account/checkout flow is offered here - payments (Epic E4 PayPal stage,
 * see DEV_NOTES.md "Deferred: PayPal Subscriptions webhook") is owner-gated
 * and not yet built. Paid-tier cards show a disabled "Coming soon" control
 * rather than a real checkout link; the free tier links to /register/.
 *
 * Fail-safe: when the plan list cannot be loaded (no DB, or DB error), this
 * page still renders a valid 200 response with an accessible "temporarily
 * unavailable" panel instead of a blank page or a PHP error - see
 * fn_pricing.php's listPublicPlansForDisplay() docblock for the fail-safe
 * contract this relies on.
 *
 * OWNER DECISION (2026-09-25, #125): the feature/limit grid ONLY ever shows
 * keys on fn_pricing.php's pricingAllowedDisplayKeys() hand-maintained
 * allow-list - never a raw, uncurated JSON key - and every row now carries a
 * 'status' of 'available' or 'coming_soon'. A feature a plan has switched on
 * but that is NOT built yet shows a visible "Coming soon" badge (text, never
 * colour alone) instead of reading as "Included"; a coming-soon LIMIT always
 * shows the same badge instead of a number nobody can actually use yet. See
 * fn_pricing.php's pricingAllowedDisplayKeys() docblock for the full
 * per-key evidence behind every 'available'/'coming_soon' verdict.
 *
 * @package     CueRCode
 * @subpackage  Pages / Pricing
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.1.0
 * @since       Phase 3 (Epic E4, #56); feature/limit allow-list + "Coming
 *              soon" badges added Phase 4 (#125, 2026-09-25)
 * @see         _functions/fn_pricing.php  - listPublicPlansForDisplay(), pricingDisplayRowsFromPlans(), pricingAllowedDisplayKeys()
 * @see         _functions/fn_features.php - resolveUpgradeUrl(), requireFeatureOr402()
 * @see         DEV_NOTES.md               - "Pricing page" section (owner price-edit runbook)
 */

// ==============================================================================
// 🚀 Load Application Bootstrap
// ==============================================================================

require_once __DIR__ . '/../../_includes/bootstrap.php';

// ==============================================================================
// 💷 Load the pricing display helpers (page-scoped only - NOT in bootstrap.php)
// ==============================================================================
// Guarded the same way public_html/account/qr-preview/index.php loads its
// page-specific fn_ file - a missing file degrades to an empty plan list
// (the fail-safe panel below) rather than a fatal error.
// ==============================================================================

$pricingFunctionFile = CUERCODE_FUNCTIONS_DIR . 'fn_pricing.php';
if (file_exists($pricingFunctionFile)) {
    require_once $pricingFunctionFile;
}

// ==============================================================================
// 📋 Fetch + shape the plans this page displays
// ==============================================================================

if (function_exists('listPublicPlansForDisplay') && function_exists('pricingDisplayRowsFromPlans')) {
    $pricingPlanRows = listPublicPlansForDisplay();
    $pricingDisplayPlans = pricingDisplayRowsFromPlans($pricingPlanRows);
} else {
    // 🚨 fn_pricing.php itself failed to load - same fail-safe outcome as an
    // empty/unavailable database: the panel below renders instead of the grid.
    $pricingDisplayPlans = [];
}

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

$pageTitle       = 'Pricing';
$pageDescription = 'Compare ' . $siteName . '\'s QR code generation plans - '
    . 'what is included free, and what Pro and Business will add.';
$bodyClass       = 'page-pricing';
$pageCSS         = [];
$pageJS          = [];

// ==============================================================================
// 📄 Render Page
// ==============================================================================

require_once CUERCODE_INCLUDES_DIR . 'header.php';
?>

    <?php /* ====================================================================
         💷 Pricing / Plan Comparison Content
         ==================================================================== */ ?>
    <section class="py-5">
        <div class="container">
            <div class="row justify-content-center mb-4">
                <div class="col-lg-8 text-center">
                    <h1 class="h2 fw-bold mb-3">
                        Plans &amp; Pricing
                    </h1>
                    <p class="text-body-secondary mb-0">
                        Make QR codes straight away with no account at all, or create a free
                        account to save your codes and create an API key.
                    </p>
                </div>
            </div>

            <?php if (empty($pricingDisplayPlans)) { ?>
            <?php /* ============================================================
                 🚨 Fail-safe panel - plan data could not be loaded (no DB,
                 or a query error). Still a valid 200 page, no PHP warnings.
                 ============================================================ */ ?>
            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <div class="alert alert-warning" role="alert">
                        <h2 class="h5 mb-2">
                            <i class="fa-solid fa-triangle-exclamation me-2" aria-hidden="true"></i>
                            Pricing is temporarily unavailable
                        </h2>
                        <p class="mb-0">
                            We could not load current plan details right now. This does not
                            affect the QR code generator itself - you can still
                            <a href="/">generate a code</a> with no account needed. Please try
                            this page again shortly, or email
                            <a href="mailto:hello@cuercode.net">hello@cuercode.net</a>
                            if the problem persists.
                        </p>
                    </div>
                </div>
            </div>
            <?php } else { ?>
            <?php /* ============================================================
                 💷 Introductory-pricing disclaimer (shown above the grid)
                 ============================================================ */ ?>
            <div class="row justify-content-center">
                <div class="col-lg-8 text-center mb-4">
                    <p class="text-body-secondary small mb-0">
                        <i class="fa-solid fa-circle-info me-1" aria-hidden="true"></i>
                        Prices shown are introductory pricing and may change - we will always
                        honour your price for any subscription already in progress.
                    </p>
                </div>
            </div>

            <?php /* ============================================================
                 🃏 Plan comparison card grid (CSS grid via Bootstrap's
                 flexbox row/col utilities - never a &lt;table&gt; for layout)
                 ============================================================ */ ?>
            <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4 justify-content-center">
                <?php foreach ($pricingDisplayPlans as $pricingPlan) { ?>
                <div class="col">
                    <div class="card h-100 shadow-sm">
                        <div class="card-body d-flex flex-column">
                            <h2 class="h4 card-title mb-1">
                                <?php echo htmlspecialchars($pricingPlan['planName'], ENT_QUOTES, 'UTF-8'); ?>
                            </h2>

                            <?php if ($pricingPlan['description'] !== '') { ?>
                            <p class="text-body-secondary small mb-3">
                                <?php echo htmlspecialchars($pricingPlan['description'], ENT_QUOTES, 'UTF-8'); ?>
                            </p>
                            <?php } ?>

                            <p class="display-6 fw-bold mb-0">
                                <?php echo htmlspecialchars($pricingPlan['priceMonthlyFormatted'], ENT_QUOTES, 'UTF-8'); ?>
                                <?php if (!$pricingPlan['isZeroPrice']) { ?>
                                <span class="fs-6 fw-normal text-body-secondary">/month</span>
                                <?php } ?>
                            </p>
                            <?php if (!$pricingPlan['isZeroPrice']) { ?>
                            <p class="text-body-secondary small mb-3">
                                or
                                <?php echo htmlspecialchars($pricingPlan['priceYearlyFormatted'], ENT_QUOTES, 'UTF-8'); ?>
                                billed yearly
                            </p>
                            <?php } else { ?>
                            <?php // 🆓 A real line in the slot the "billed yearly" text uses on paid plans, so the cards line up without a blank spacer ?>
                            <p class="text-body-secondary small mb-3">No payment details needed</p>
                            <?php } ?>

                            <?php /* ================================================
                                 🚩 Feature rows - included/excluded/coming-soon
                                 is NEVER conveyed by colour alone: a
                                 differently-shaped icon (or the "Coming soon"
                                 badge's own visible TEXT, #125) PLUS a
                                 screen-reader-only prefix both carry the
                                 meaning independently of colour, in both dark
                                 mode and every colourblind-mode palette (the
                                 badge reuses the site's standard Bootstrap
                                 text-bg-*-subtle tokens, which are already
                                 re-themed for both by cuercode.css/Bootstrap
                                 itself - no feature-specific colour is used).
                                 ================================================ */ ?>
                            <?php if (!empty($pricingPlan['features'])) { ?>
                            <ul class="list-unstyled mb-3">
                                <?php foreach ($pricingPlan['features'] as $pricingFeature) { ?>
                                <?php
                                    // 🚦 #125: a feature the plan has switched
                                    // on but that is not yet BUILT (owner's
                                    // hand-maintained 'coming_soon' status,
                                    // see fn_pricing.php's
                                    // pricingAllowedDisplayKeys()) must never
                                    // read as "Included" - it gets its own
                                    // distinct "Coming soon" treatment instead
                                    // of the usual check/cross icon pairing.
                                    $pricingFeatureIsComingSoon = ($pricingFeature['status'] === 'coming_soon')
                                        && $pricingFeature['enabled'];
                                ?>
                                <li class="mb-2 <?php echo ($pricingFeature['enabled'] && !$pricingFeatureIsComingSoon) ? '' : 'text-body-secondary'; ?>">
                                    <?php if ($pricingFeatureIsComingSoon) { ?>
                                    <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle me-2">
                                        <i class="fa-solid fa-clock me-1" aria-hidden="true"></i>
                                        Coming soon
                                    </span>
                                    <span class="visually-hidden">- not yet available: </span>
                                    <?php } elseif ($pricingFeature['enabled']) { ?>
                                    <i class="fa-solid fa-check text-success me-2" aria-hidden="true"></i>
                                    <span class="visually-hidden">Included: </span>
                                    <?php } else { ?>
                                    <i class="fa-solid fa-xmark me-2" aria-hidden="true"></i>
                                    <span class="visually-hidden">Not included: </span>
                                    <?php } ?>
                                    <?php echo htmlspecialchars($pricingFeature['label'], ENT_QUOTES, 'UTF-8'); ?>
                                </li>
                                <?php } ?>
                            </ul>
                            <?php } ?>

                            <?php /* ================================================
                                 🔢 Limit rows (saved codes, bulk batch size, ...)
                                 - a limit whose underlying feature is not yet
                                 built (#125 'coming_soon') shows the SAME
                                 "Coming soon" badge instead of a numeric value
                                 that nobody can actually use yet, regardless
                                 of what the plan's own limitsJSON says.
                                 ================================================ */ ?>
                            <?php if (!empty($pricingPlan['limits'])) { ?>
                            <ul class="list-unstyled mb-3 small text-body-secondary">
                                <?php foreach ($pricingPlan['limits'] as $pricingLimit) { ?>
                                <li class="mb-1">
                                    <i class="fa-solid fa-gauge-high me-2" aria-hidden="true"></i>
                                    <?php echo htmlspecialchars($pricingLimit['label'], ENT_QUOTES, 'UTF-8'); ?>:
                                    <?php if ($pricingLimit['status'] === 'coming_soon') { ?>
                                    <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">
                                        <i class="fa-solid fa-clock me-1" aria-hidden="true"></i>
                                        Coming soon
                                    </span>
                                    <?php } else { ?>
                                    <strong><?php echo htmlspecialchars($pricingLimit['value'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <?php } ?>
                                </li>
                                <?php } ?>
                            </ul>
                            <?php } ?>

                            <div class="mt-auto">
                                <?php if ($pricingPlan['isZeroPrice']) { ?>
                                <?php /* 🆓 Zero-price plan: a real, working CTA to
                                     account registration. */ ?>
                                <a href="/register/" class="btn btn-primary w-100">
                                    <i class="fa-solid fa-user-plus me-1" aria-hidden="true"></i>
                                    Create free account
                                </a>
                                <p class="small text-body-secondary mt-2 mb-0">
                                    No account needed to generate a QR code right now -
                                    <a href="/">use the generator</a>. A free account adds
                                    saved codes and an API key.
                                </p>
                                <?php } else { ?>
                                <?php /* 💳 Paid plan: checkout is owner-gated and not
                                     yet built (Epic E4 payments stage) - a
                                     disabled control, never an invented flow. */ ?>
                                <button type="button" class="btn btn-outline-secondary w-100" disabled aria-disabled="true">
                                    Coming soon
                                </button>
                                <?php } ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php } ?>
            </div>
            <?php } ?>
        </div>
    </section>

<?php
require_once CUERCODE_INCLUDES_DIR . 'footer.php';
