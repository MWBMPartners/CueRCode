<?php
/**
 * ==============================================================================
 * 🔭 CueRCode - Admin: Pricing page preview (Global Admins only)
 * ==============================================================================
 *
 * Path: public_html/admin/pricing-preview/index.php?currency=<code>
 *
 * The public pricing page as it WILL look when it reads price points (task 7n
 * Phase 2, plan §4.7 and §7.1): the same cards (_includes/pricing_grid.php),
 * the same VAT wording and the same "hide prices" setting, with a currency
 * switcher made of ordinary links, so it works without JavaScript. A price
 * that does not exist in the chosen currency is shown as missing - never as
 * "Free", and never borrowed from another currency.
 *
 * It uses the current monthly and yearly price of each plan, chosen exactly as
 * the copy to the public page chooses them (adminMirrorSelection()).
 *
 * Read-only: this page changes nothing, and it does not touch any charging
 * switch. The public page keeps using the older single-price columns.
 *
 * 🚪 requireGlobalAdminPage() is the first application call (a test checks
 *    this for every admin page); the page is also marked noindex.
 *
 * @package     CueRCode
 * @subpackage  Admin
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-26 (task 7n Phase 2, slice 2d - #31)
 * @see         _functions/fn_pricing.php - pricingPreviewRows()
 * @see         .claude/plans/7n-admin-commerce-plan.md §4.7, §7.1
 */

// ==============================================================================
// 🚀 Load the application, then the admin functions, then the gate - FIRST
// ==============================================================================
require_once __DIR__ . '/../../../_includes/bootstrap.php';
require_once CUERCODE_FUNCTIONS_DIR . 'fn_admin.php';

$adminUser = requireGlobalAdminPage('/admin/pricing-preview/');

// 💳 The plan readers and the public page's display helpers (after the gate).
require_once CUERCODE_FUNCTIONS_DIR . 'fn_admin_plans.php';
require_once CUERCODE_FUNCTIONS_DIR . 'fn_pricing.php';

// ==============================================================================
// 📥 What the page shows
// ==============================================================================
$previewReady      = adminDatabaseReadyForChanges();
$previewCurrencies = ['ok' => false, 'offered' => [], 'default' => ''];
$previewCurrency   = '';
$pricingDisplayPlans = [];
$previewReadProblem  = false;

if ($previewReady) {
    $previewCurrencies = adminPlansCurrencySettings();
    $previewPrices     = adminPlansLoadAllPrices();

    if (!$previewCurrencies['ok'] || $previewPrices === null) {
        $previewReadProblem = true;
    } else {
        // 💱 Only an offered currency may be chosen; anything else (or
        //    nothing) shows the main currency.
        $previewCurrency = $previewCurrencies['default'];
        if (isset($_GET['currency']) && is_string($_GET['currency']) && in_array($_GET['currency'], $previewCurrencies['offered'], true)) {
            $previewCurrency = $_GET['currency'];
        }

        $previewPlanRows   = listPublicPlansForDisplay();
        $previewNowUtc     = adminPlansNowUtc();
        $previewSelections = [];
        foreach ($previewPlanRows as $previewPlanRow) {
            $previewPlanID = (int) $previewPlanRow['planID'];
            $previewPlanPrices = [];
            if (isset($previewPrices[$previewPlanID])) {
                $previewPlanPrices = $previewPrices[$previewPlanID];
            }
            $previewSelections[$previewPlanID] = adminMirrorSelection($previewPlanPrices, $previewCurrency, $previewNowUtc);
        }

        $pricingDisplayPlans = pricingDisplayRowsFromPlans(
            pricingPreviewRows($previewPlanRows, $previewSelections, $previewCurrency)
        );
    }
}

// ⚙️ The same settings the public page reads.
$pricingShowPrices      = pricingShowPrices(getConfig('pricing_show_prices', true));
$pricingVatMode         = getConfig('billing_vat_mode', 'inclusive');
if (!is_string($pricingVatMode)) {
    $pricingVatMode = 'inclusive';
}
$pricingVatNote         = pricingVatNote($pricingVatMode);
$pricingSiteEmail       = getConfig('site_email', 'hello@cuercode.net');
$pricingPreviewCurrency = $previewCurrency;

// 🤝 Decision 0020 (#159): the site-wide price promise, for the plans that do
//    not set their own (each card states its plan's promise).
$pricingPromiseDefaults = ['mode' => null, 'months' => null];
if (function_exists('getConfig')) {
    $pricingPromiseDefaults['mode']   = getConfig('billing_price_promise_default_mode', null);
    $pricingPromiseDefaults['months'] = getConfig('billing_price_promise_default_months', null);
}

// ==============================================================================
// 📄 Page variables
// ==============================================================================
$pageTitle       = 'Pricing page preview';
$pageDescription = 'Private preview of the pricing page for CueRCode Global Admins.';
$pageNoIndex     = true;
$bodyClass       = 'page-admin page-admin-pricing-preview page-pricing';
$pageCSS         = [];
$pageJS          = ['/assets/js/cuercode-admin.js'];

$adminActive = 'pricing-preview';

require_once CUERCODE_INCLUDES_DIR . 'header.php';
?>

    <section class="py-5">
        <div class="container">
            <div class="alert alert-warning text-center" role="status">
                <p class="fw-bold mb-1">
                    <i class="fa-solid fa-eye me-1" aria-hidden="true"></i>
                    Preview - not public
                </p>
                <p class="mb-0 small">
                    This is how the pricing page will look when it uses the prices set for each currency.
                    Visitors still see the <a href="/pricing/" class="alert-link">current pricing page</a>. Charging stays switched off.
                </p>
            </div>

            <?php /* 🧭 The admin menu, folded away so the preview keeps the public page's width. */ ?>
            <details class="mb-4">
                <summary class="mb-2">Admin sections</summary>
                <?php require_once CUERCODE_INCLUDES_DIR . 'admin_nav.php'; ?>
            </details>

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

            <?php if (!$previewReady) { ?>
            <div class="alert alert-warning" role="status">
                <i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i>
                Database upgrade needed before the preview can be shown. <a href="/admin/upgrades/">See database upgrades</a>.
            </div>
            <?php } elseif ($previewReadProblem || $pricingDisplayPlans === []) { ?>
            <div class="alert alert-danger" role="alert">
                <i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i>
                <?php if ($previewReadProblem) { ?>
                The plans and prices could not be read just now. Please reload the page.
                <?php } else { ?>
                There is no plan to show. A plan appears when it is an ordinary plan, on sale, inside its sale dates
                and has a display order above 0. <a href="/admin/plans/">See the plans</a>.
                <?php } ?>
            </div>
            <?php } else { ?>
            <?php /* 💱 Currency switcher: plain links, so no JavaScript is needed. */ ?>
            <nav aria-label="Preview currency" class="mb-3">
                <ul class="nav nav-pills justify-content-center gap-2">
                    <?php foreach ($previewCurrencies['offered'] as $previewChoice) { ?>
                    <?php
                    $previewLinkClass = 'nav-link border';
                    if ($previewChoice === $previewCurrency) {
                        $previewLinkClass .= ' active';
                    }
                    ?>
                    <li class="nav-item">
                        <a class="<?php echo adminEscape($previewLinkClass); ?>"
                           href="/admin/pricing-preview/?currency=<?php echo adminEscape(rawurlencode($previewChoice)); ?>"
                           <?php if ($previewChoice === $previewCurrency) { ?>aria-current="true"<?php } ?>>
                            <span class="visually-hidden">Show prices in </span><?php echo adminEscape($previewChoice); ?>
                        </a>
                    </li>
                    <?php } ?>
                </ul>
            </nav>
            <?php if (!$pricingShowPrices) { ?>
            <p class="text-center small mb-4">
                Price figures are hidden because "show prices" is switched off on the
                <a href="/admin/settings/">settings page</a>.
            </p>
            <?php } ?>

            <?php require_once CUERCODE_INCLUDES_DIR . 'pricing_grid.php'; ?>
            <?php } ?>
        </div>
    </section>

<?php
require_once CUERCODE_INCLUDES_DIR . 'footer.php';
