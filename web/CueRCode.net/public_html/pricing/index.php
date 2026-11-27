<?php
/**
 * ==============================================================================
 * 💷 CueRCode - Public Pricing / Plan-Comparison Page (Epic E4, #56)
 * ==============================================================================
 *
 * Path: public_html/pricing/index.php
 *
 * A plain, server-rendered plan-comparison page - one card per publicly
 * offered ordinary plan, sourced entirely from tblSubscriptionPlans via
 * fn_pricing.php's listPublicPlansForDisplay()/pricingDisplayRowsFromPlans().
 * The cards themselves are drawn by _includes/pricing_grid.php, which the
 * Global Admin preview (/admin/pricing-preview/) uses too. Price figures are
 * hidden when the pricing_show_prices setting is off; a "Contact us" plan gets
 * a contact link instead of a buy button.
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
 * @version     1.2.0
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

// 🧾 The VAT wording next to the prices (an admin picks it on /admin/settings/).
//    Fail-safe: when the setting cannot be read the seeded default is used.
$pricingVatMode = 'inclusive';
if (function_exists('getConfig')) {
    $pricingVatConfigured = getConfig('billing_vat_mode', 'inclusive');
    if (is_string($pricingVatConfigured)) {
        $pricingVatMode = $pricingVatConfigured;
    }
}
$pricingVatNote = '';
if (function_exists('pricingVatNote')) {
    $pricingVatNote = pricingVatNote($pricingVatMode);
}

// 👁️ Whether price figures are shown (admin setting; on when it cannot be read).
$pricingShowPrices = true;
if (function_exists('getConfig') && function_exists('pricingShowPrices')) {
    $pricingShowPrices = pricingShowPrices(getConfig('pricing_show_prices', true));
}

// ✉️ Where "Contact us" goes when a plan has no address of its own.
$pricingSiteEmail = '';
if (function_exists('getConfig')) {
    $pricingSiteEmail = getConfig('site_email', 'hello@cuercode.net');
}

// 🤝 Decision 0020 (#159): the site-wide price promise, for the plans that do
//    not set their own (each card states its plan's promise).
$pricingPromiseDefaults = ['mode' => null, 'months' => null];
if (function_exists('getConfig')) {
    $pricingPromiseDefaults['mode']   = getConfig('billing_price_promise_default_mode', null);
    $pricingPromiseDefaults['months'] = getConfig('billing_price_promise_default_months', null);
}

// 🌍 The public page shows each plan's own currency (no preview).
$pricingPreviewCurrency = '';

$pageTitle       = 'Pricing';
$pageDescription = 'Compare ' . $siteName . '\'s QR code generation plans - '
    . 'what is included free, and what the paid plans will add.';
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
            <?php require_once CUERCODE_INCLUDES_DIR . 'pricing_grid.php'; ?>
            <?php } ?>
        </div>
    </section>

<?php
require_once CUERCODE_INCLUDES_DIR . 'footer.php';
