<?php
/**
 * ==============================================================================
 * 💳 CueRCode - Admin: Plans (Global Admins only)
 * ==============================================================================
 *
 * Path: public_html/admin/plans/index.php
 *
 * The list of every plan (task 7n Phase 2, plan §7.1), grouped by kind
 * (ordinary plans, lifetime deals, credit packs): whether each is on sale,
 * how many paying subscribers it has, its current prices in each offered
 * currency, and a warning for any price group missing a currency. Links to
 * the edit and prices screens, and "Add a plan".
 *
 * Read-only: this page changes nothing (no forms that save).
 *
 * 🚪 requireGlobalAdminPage() is the first application call (a test checks
 *    this for every admin page).
 *
 * @package     CueRCode
 * @subpackage  Admin
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-26 (task 7n Phase 2, slice 2c - #31)
 * @see         _functions/fn_admin_plans.php
 * @see         .claude/plans/7n-admin-commerce-plan.md §7.1
 */

// ==============================================================================
// 🚀 Load the application, then the admin functions, then the gate - FIRST
// ==============================================================================
require_once __DIR__ . '/../../../_includes/bootstrap.php';
require_once CUERCODE_FUNCTIONS_DIR . 'fn_admin.php';

$adminUser = requireGlobalAdminPage('/admin/plans/');

// 💳 The plans editor itself (loaded after the gate).
require_once CUERCODE_FUNCTIONS_DIR . 'fn_admin_plans.php';

// ==============================================================================
// 📥 What the page shows
// ==============================================================================
$plansTimezone = adminDisplayTimezone();
$plansNowUtc   = adminPlansNowUtc();
$plansReady    = adminDatabaseReadyForChanges();

$plansAll        = null;
$plansPrices     = null;
$plansPaying     = null;
$plansCurrencies = ['ok' => false, 'offered' => [], 'default' => ''];

// 🧱 The plan columns come from database upgrade 0004: read them only once
//    every upgrade has run (otherwise the page says an upgrade is needed).
if ($plansReady) {
    $plansAll        = adminPlansLoadAllPlans();
    $plansPrices     = adminPlansLoadAllPrices();
    $plansPaying     = adminPlansPayingCounts();
    $plansCurrencies = adminPlansCurrencySettings();
}

$plansLoaded = ($plansAll !== null && $plansPrices !== null && $plansPaying !== null && $plansCurrencies['ok']);

// 🗂️ Plans by kind, in the kind order of adminPlansKinds().
$plansByKind = [];
foreach (array_keys(adminPlansKinds()) as $plansKindKey) {
    $plansByKind[$plansKindKey] = [];
}
if ($plansLoaded) {
    foreach ($plansAll as $plansRow) {
        $plansKindOfRow = (string) $plansRow['planKind'];
        if (!isset($plansByKind[$plansKindOfRow])) {
            $plansByKind[$plansKindOfRow] = [];
        }
        $plansByKind[$plansKindOfRow][] = $plansRow;
    }
}

// ==============================================================================
// 📄 Page variables
// ==============================================================================
$pageTitle       = 'Plans';
$pageDescription = 'Private plan list for CueRCode Global Admins.';
$pageNoIndex     = true;
$bodyClass       = 'page-admin page-admin-plans';
$pageCSS         = [];
$pageJS          = ['/assets/js/cuercode-admin.js'];

$adminActive = 'plans';
$plansMessages = adminFlashTake();

require_once CUERCODE_INCLUDES_DIR . 'header.php';
?>

    <section class="py-5">
        <div class="container">
            <div class="row">
                <div class="col-lg-3">
                    <?php require_once CUERCODE_INCLUDES_DIR . 'admin_nav.php'; ?>
                </div>

                <div class="col-lg-9">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                        <h1 class="h2 fw-bold mb-0">Plans</h1>
                        <?php if ($plansReady) { ?>
                        <a class="btn btn-primary" href="/admin/plans/new/"><i class="fa-solid fa-plus me-1" aria-hidden="true"></i> Add a plan</a>
                        <?php } ?>
                    </div>
                    <p class="text-body-secondary mb-4">
                        Every plan, its prices and who is on it. Prices are set in each offered currency. While charging is off, the
                        pricing page shows each plan's monthly and yearly price in the main currency<?php if ($plansCurrencies['default'] !== '') { ?> (<?php echo adminEscape($plansCurrencies['default']); ?>)<?php } ?>.
                        Charging itself stays switched off.
                    </p>

                    <?php echo adminRenderMessages($plansMessages); ?>

                    <?php if (!$plansReady) { ?>
                    <div class="alert alert-warning" role="status">
                        <i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i>
                        Database upgrade needed before plans can be shown or changed. <a href="/admin/upgrades/">See database upgrades</a>.
                    </div>
                    <?php } elseif (!$plansLoaded) { ?>
                    <div class="alert alert-danger" role="alert">
                        <i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i>
                        The plans could not be read just now. Please reload the page.
                    </div>
                    <?php } else { ?>

                    <?php foreach ($plansByKind as $plansKindKey => $plansOfKind) { ?>
                    <?php
                    $plansKindWords = ['label' => 'Other plans (' . $plansKindKey . ')', 'help' => ''];
                    $plansKinds = adminPlansKinds();
                    if (isset($plansKinds[$plansKindKey])) {
                        $plansKindWords = $plansKinds[$plansKindKey];
                    }
                    ?>
                    <h2 class="h4 mt-4"><?php echo adminEscape($plansKindWords['label']); ?></h2>
                    <?php if ($plansKindWords['help'] !== '') { ?>
                    <p class="small text-body-secondary"><?php echo adminEscape($plansKindWords['help']); ?></p>
                    <?php } ?>

                    <?php if ($plansOfKind === []) { ?>
                    <p class="text-body-secondary">None yet.</p>
                    <?php } ?>

                    <div class="row g-3">
                    <?php foreach ($plansOfKind as $plansRow) { ?>
                        <?php
                        $plansID = (int) $plansRow['planID'];
                        $plansRowPrices = [];
                        if (isset($plansPrices[$plansID])) {
                            $plansRowPrices = $plansPrices[$plansID];
                        }
                        $plansPayingCount = 0;
                        if (isset($plansPaying[$plansID])) {
                            $plansPayingCount = $plansPaying[$plansID];
                        }
                        $plansStatus = adminPlansSaleStatus($plansRow, $plansNowUtc);
                        $plansWarnings = adminPlansMissingCurrencyWarnings($plansRowPrices, (string) $plansRow['planKind'], $plansCurrencies['offered']);
                        ?>
                        <div class="col-12 col-xl-6">
                            <div class="card shadow-sm h-100">
                                <div class="card-body">
                                    <h3 class="h5 mb-1 text-break"><?php echo adminEscape($plansRow['planName']); ?></h3>
                                    <p class="small text-body-secondary mb-2">Key: <code><?php echo adminEscape($plansRow['planKey']); ?></code></p>
                                    <p class="mb-1"><?php echo adminStatusHtml($plansStatus['kind'], $plansStatus['text']); ?></p>
                                    <p class="mb-2 small">
                                        <i class="fa-solid fa-users me-1" aria-hidden="true"></i>
                                        Paying subscribers: <?php echo (int) $plansPayingCount; ?>
                                    </p>

                                    <?php
                                    // 💷 Current prices, one line per group, every offered currency.
                                    $plansGroups = adminPlansPriceGroups((string) $plansRow['planKind']);
                                    ?>
                                    <?php if ($plansGroups !== []) { ?>
                                    <dl class="row small mb-2">
                                        <?php foreach ($plansGroups as $plansGroupKey => $plansGroup) { ?>
                                        <?php
                                        $plansCurrent = adminPlansCurrentByCurrency($plansRowPrices, $plansGroupKey);
                                        $plansPriceWords = [];
                                        foreach ($plansCurrencies['offered'] as $plansCurrency) {
                                            if (isset($plansCurrent[$plansCurrency][0])) {
                                                $plansMinor = moneyStoredDecimalToMinor((string) $plansCurrent[$plansCurrency][0]['amount'], $plansCurrency);
                                                if ($plansMinor !== null) {
                                                    $plansPriceWords[] = moneyFormat($plansMinor, $plansCurrency);
                                                }
                                            }
                                        }
                                        if ($plansPriceWords === []) {
                                            $plansPriceWords[] = 'No price';
                                        }
                                        ?>
                                        <dt class="col-5"><?php echo adminEscape($plansGroup['label']); ?></dt>
                                        <dd class="col-7 mb-1"><?php echo adminEscape(implode(' / ', $plansPriceWords)); ?></dd>
                                        <?php } ?>
                                    </dl>
                                    <?php } ?>

                                    <?php if ($plansWarnings !== []) { ?>
                                    <ul class="small list-unstyled mb-2">
                                        <?php foreach ($plansWarnings as $plansWarning) { ?>
                                        <li><?php echo adminStatusHtml('warning', $plansWarning); ?></li>
                                        <?php } ?>
                                    </ul>
                                    <?php } ?>

                                    <div class="d-flex flex-wrap gap-2 mt-3">
                                        <a class="btn btn-sm btn-outline-primary" href="/admin/plans/edit/?id=<?php echo $plansID; ?>">
                                            <i class="fa-solid fa-pen me-1" aria-hidden="true"></i> Details<span class="visually-hidden"> of <?php echo adminEscape($plansRow['planName']); ?></span>
                                        </a>
                                        <?php if ($plansGroups !== []) { ?>
                                        <a class="btn btn-sm btn-outline-primary" href="/admin/plans/prices/?id=<?php echo $plansID; ?>">
                                            <i class="fa-solid fa-sterling-sign me-1" aria-hidden="true"></i> Prices<span class="visually-hidden"> of <?php echo adminEscape($plansRow['planName']); ?></span>
                                        </a>
                                        <?php } ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php } ?>
                    </div>
                    <?php } ?>

                    <?php } ?>
                </div>
            </div>
        </div>
    </section>

<?php
require_once CUERCODE_INCLUDES_DIR . 'footer.php';
