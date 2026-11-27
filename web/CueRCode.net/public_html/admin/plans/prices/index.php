<?php
/**
 * ==============================================================================
 * 💷 CueRCode - Admin: Plan prices (Global Admins only)
 * ==============================================================================
 *
 * Path: public_html/admin/plans/prices/index.php?id=<planID>
 *
 * The prices of one plan (task 7n Phase 2, plan §7.1), one form per price
 * group ("Monthly", "Yearly" - or "Pay once" for a lifetime deal) with one
 * amount box per offered currency, the group's sale dates and whether it is
 * the recommended choice; then every price the plan has ever had, with
 * Retire and (for a price nobody bought) Delete.
 *
 * 🧊 P1, on the server: a price somebody has bought cannot be changed or
 *    deleted - it is retired and a new one added. The check runs INSIDE the
 *    save's locked transaction, so a purchase recorded while the form was
 *    open is still noticed. Amounts are whole pence (fn_money.php), never
 *    floats.
 *
 * 🪞 After every save the plan's monthly and yearly prices in the main
 *    currency are copied to the columns the pricing page reads while
 *    charging is off (adminMirrorLegacyPlanPrices()).
 *
 * 🔒 Every POST: CSRF token, same-site check, fresh password confirmation,
 *    database ready, then ONE transaction (lock plan and prices, check the
 *    fingerprint, save, audit, mirror), then a redirect.
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
 * @see         .claude/plans/7n-admin-commerce-plan.md §4.3 (P1), §4.7, §7.1, §8
 */

// ==============================================================================
// 🚀 Load the application, then the admin functions, then the gate - FIRST
// ==============================================================================
require_once __DIR__ . '/../../../../_includes/bootstrap.php';
require_once CUERCODE_FUNCTIONS_DIR . 'fn_admin.php';

$adminUser = requireGlobalAdminPage('/admin/plans/prices/');

// 💳 The plans editor itself (loaded after the gate).
require_once CUERCODE_FUNCTIONS_DIR . 'fn_admin_plans.php';

// ==============================================================================
// 📋 Which plan, and the editing state
// ==============================================================================
$pricesPlanID      = adminPlansIdFromQuery($_GET);
$pricesSelfPath    = '/admin/plans/prices/?id=' . $pricesPlanID;
$pricesTimezone    = adminDisplayTimezone();
$pricesReady       = adminDatabaseReadyForChanges();
$pricesSecondsLeft = adminReauthSecondsLeftForAdmin($adminUser);
$pricesCanEdit     = ($pricesReady && $pricesSecondsLeft > 0);

$pricesMessages    = [];
$pricesErrors      = [];
$pricesErrorGroup  = '';     // the group whose form had the problem
$pricesPosted      = [];     // what was typed in that form
$pricesPostedFp    = '';

// ==============================================================================
// 📤 POST: save a group, retire a price, or delete a price
// ==============================================================================
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // 🛡️ CSRF → same site → fresh password (plan §6.3, §6.4).
    $pricesCheck = adminCheckPost($adminUser, true);

    $pricesAction = '';
    if (isset($_POST['price_action']) && is_string($_POST['price_action'])) {
        $pricesAction = $_POST['price_action'];
    }
    $pricesFp = '';
    if (isset($_POST['fp']) && is_string($_POST['fp'])) {
        $pricesFp = $_POST['fp'];
    }
    $pricesConfirmed = (isset($_POST['confirm']) && $_POST['confirm'] === '1');
    $pricesPointID = 0;
    if (isset($_POST['price_id']) && is_string($_POST['price_id']) && preg_match('/^[1-9][0-9]{0,9}$/D', $_POST['price_id']) === 1) {
        $pricesPointID = (int) $_POST['price_id'];
    }

    $pricesResult = null;

    if (!$pricesCheck['ok']) {
        if ($pricesCheck['reason'] !== 'reauth') {
            http_response_code(403);
        }
        $pricesMessages[] = ['type' => 'danger', 'text' => $pricesCheck['message']];
    } elseif (!$pricesReady) {
        $pricesMessages[] = ['type' => 'danger', 'text' => 'Database upgrade needed, so nothing was saved. Apply the waiting upgrades on the database upgrades page first.'];
    } elseif ($pricesPlanID === 0) {
        $pricesMessages[] = ['type' => 'danger', 'text' => 'No plan was chosen, so nothing was saved.'];
    } elseif ($pricesAction === 'save_group') {
        $pricesGroupKey = '';
        if (isset($_POST['group']) && is_string($_POST['group'])) {
            $pricesGroupKey = $_POST['group'];
        }
        $pricesResult = adminPricesSaveGroup($adminUser, $pricesPlanID, $pricesGroupKey, $pricesFp, $_POST);
        if (!$pricesResult['ok']) {
            $pricesErrorGroup = $pricesGroupKey;
            $pricesPostedFp = $pricesFp;
            foreach (['valid_from_date', 'valid_from_time', 'valid_until_date', 'valid_until_time', 'recommended'] as $pricesBox) {
                if (isset($_POST[$pricesBox]) && is_string($_POST[$pricesBox])) {
                    $pricesPosted[$pricesBox] = $_POST[$pricesBox];
                }
            }
            $pricesPosted['amount'] = [];
            if (isset($_POST['amount']) && is_array($_POST['amount'])) {
                foreach ($_POST['amount'] as $pricesCurrency => $pricesTyped) {
                    if (is_string($pricesCurrency) && is_string($pricesTyped)) {
                        $pricesPosted['amount'][$pricesCurrency] = $pricesTyped;
                    }
                }
            }
        }
    } elseif ($pricesAction === 'retire') {
        $pricesResult = adminPricesRetire($adminUser, $pricesPlanID, $pricesPointID, $pricesFp, $pricesConfirmed);
    } elseif ($pricesAction === 'delete') {
        $pricesResult = adminPricesDelete($adminUser, $pricesPlanID, $pricesPointID, $pricesFp, $pricesConfirmed);
    } else {
        $pricesMessages[] = ['type' => 'danger', 'text' => 'That request was not understood, so nothing was saved.'];
    }

    if (is_array($pricesResult)) {
        if ($pricesResult['ok']) {
            adminReauthTouch($adminUser);
            $pricesFlashType = 'info';
            if ($pricesResult['changed']) {
                $pricesFlashType = 'success';
            }
            adminFlashAdd($pricesFlashType, $pricesResult['message'], $pricesResult['warnings']);
            adminRedirect($pricesSelfPath);
        }
        $pricesErrors = $pricesResult['errors'];
        if ($pricesErrors === [] || $pricesResult['stale']) {
            $pricesMessages[] = ['type' => 'danger', 'text' => $pricesResult['message']];
        }
    }

    $pricesSecondsLeft = adminReauthSecondsLeftForAdmin($adminUser);
    $pricesCanEdit     = ($pricesReady && $pricesSecondsLeft > 0);
}

// ==============================================================================
// 📥 Read the plan, its prices and what refers to them
// ==============================================================================
$pricesPlan = null;
$pricesRows = [];
$pricesReferences = [];
$pricesCurrencies = ['ok' => false, 'offered' => [], 'default' => ''];
$pricesReadProblem = false;

if ($pricesReady && $pricesPlanID > 0) {
    $pricesLoaded = adminPlansLoadPlan($pricesPlanID, false);
    $pricesCurrencies = adminPlansCurrencySettings();
    if (!$pricesLoaded['ok'] || !$pricesCurrencies['ok']) {
        $pricesReadProblem = true;
    } else {
        $pricesPlan = $pricesLoaded['row'];
    }
}

if (is_array($pricesPlan)) {
    $pricesLoadedRows = adminPlansLoadPrices($pricesPlanID, false);
    if ($pricesLoadedRows === null) {
        $pricesReadProblem = true;
    } else {
        $pricesRows = $pricesLoadedRows;
        $pricesIDs = [];
        foreach ($pricesRows as $pricesRow) {
            $pricesIDs[] = (int) $pricesRow['pricePointID'];
        }
        $pricesLoadedRefs = adminPlansPriceReferences($pricesIDs, false);
        if ($pricesLoadedRefs === null) {
            $pricesReadProblem = true;
        } else {
            $pricesReferences = $pricesLoadedRefs;
        }
    }
}

if ($pricesReady && !$pricesReadProblem && $pricesPlan === null) {
    http_response_code(404);
}

// 🏷️ Words for each currency box: "GBP - Pound sterling (£)".
$pricesCurrencyFacts = moneyCurrencyInfo();
$pricesCurrencyWords = static function (string $code) use ($pricesCurrencyFacts): string {
    if (isset($pricesCurrencyFacts[$code])) {
        return $code . ' - ' . $pricesCurrencyFacts[$code]['name'] . ' (' . $pricesCurrencyFacts[$code]['symbol'] . ')';
    }
    return $code;
};

// 💬 An amount from the database for people ("£6.99"), never via a float.
$pricesAmountWords = static function (string $stored, string $currency): string {
    if (!moneyCurrencyKnown($currency)) {
        return $stored . ' ' . $currency;
    }
    $minor = moneyStoredDecimalToMinor($stored, $currency);
    if ($minor === null) {
        return $stored . ' ' . $currency;
    }
    return moneyFormat($minor, $currency);
};

// ==============================================================================
// 📄 Page variables
// ==============================================================================
$pricesTitleName = 'Plan';
if (is_array($pricesPlan)) {
    $pricesTitleName = (string) $pricesPlan['planName'];
}
$pageTitle       = 'Prices: ' . $pricesTitleName;
$pageDescription = 'Private price editor for CueRCode Global Admins.';
$pageNoIndex     = true;
$bodyClass       = 'page-admin page-admin-plans';
$pageCSS         = [];
$pageJS          = ['/assets/js/cuercode-admin.js'];

$adminActive = 'plans';
$pricesMessages = array_merge(adminFlashTake(), $pricesMessages);

require_once CUERCODE_INCLUDES_DIR . 'header.php';
?>

    <section class="py-5">
        <div class="container">
            <div class="row">
                <div class="col-lg-3">
                    <?php require_once CUERCODE_INCLUDES_DIR . 'admin_nav.php'; ?>
                </div>

                <div class="col-lg-9">
                    <p class="mb-2"><a href="/admin/plans/"><i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i>All plans</a></p>

                    <?php if (!$pricesReady) { ?>
                    <h1 class="h2 fw-bold mb-3">Prices</h1>
                    <?php echo adminPlansEditStateHtml(false, 0, time(), $pricesTimezone, $pricesSelfPath); ?>
                    <?php } elseif ($pricesReadProblem) { ?>
                    <h1 class="h2 fw-bold mb-3">Prices</h1>
                    <div class="alert alert-danger" role="alert">
                        <i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i>
                        The prices could not be read just now. Please reload the page.
                    </div>
                    <?php } elseif ($pricesPlan === null) { ?>
                    <h1 class="h2 fw-bold mb-3">Plan not found</h1>
                    <?php echo adminRenderMessages($pricesMessages); ?>
                    <p>That plan does not exist (it may have been deleted). <a href="/admin/plans/">Go back to the list of plans</a>.</p>
                    <?php } else { ?>
                    <?php
                    $pricesFp = adminPlansPricesFingerprint($pricesRows);
                    $pricesGroups = adminPlansPriceGroups((string) $pricesPlan['planKind']);
                    $pricesNowUtc = adminPlansNowUtc();
                    $pricesShownCurrency = (string) $pricesPlan['currency'];
                    ?>
                    <h1 class="h2 fw-bold mb-1 text-break">Prices: <?php echo adminEscape($pricesPlan['planName']); ?></h1>
                    <p class="mb-3"><a class="small" href="/admin/plans/edit/?id=<?php echo (int) $pricesPlanID; ?>">Details of this plan</a></p>

                    <?php echo adminRenderMessages($pricesMessages); ?>
                    <?php
                    $pricesLabels = [];
                    foreach ($pricesCurrencies['offered'] as $pricesCurrency) {
                        $pricesLabels['amount_' . $pricesCurrency] = $pricesCurrencyWords($pricesCurrency);
                    }
                    $pricesLabels['valid_from_date'] = 'On sale from';
                    $pricesLabels['valid_until_date'] = 'On sale until';
                    $pricesSummaryPrefix = 'f-' . $pricesErrorGroup . '-';
                    if ($pricesErrorGroup === '') {
                        $pricesSummaryPrefix = 'f-';
                        foreach ($pricesErrors as $pricesErrorKey => $pricesErrorText) {
                            $pricesLabels[$pricesErrorKey] = 'Confirm';
                        }
                    }
                    echo adminPlansErrorSummaryHtml($pricesErrors, $pricesLabels, $pricesSummaryPrefix);
                    ?>
                    <?php echo adminPlansEditStateHtml($pricesReady, $pricesSecondsLeft, time(), $pricesTimezone, $pricesSelfPath); ?>

                    <?php /* 🪞 What the public page shows right now */ ?>
                    <div class="card shadow-sm mb-4">
                        <div class="card-body">
                            <p class="mb-1 fw-bold">What the pricing page shows now (charging is off)</p>
                            <p class="mb-1">
                                Monthly: <?php echo adminEscape($pricesAmountWords((string) $pricesPlan['priceMonthly'], $pricesShownCurrency)); ?>,
                                yearly: <?php echo adminEscape($pricesAmountWords((string) $pricesPlan['priceYearly'], $pricesShownCurrency)); ?>
                                (0 is shown as "Free" only on the two plans people get without paying - the visitors' plan and the free plan new accounts get; any other plan with no price shows "Price coming soon").
                            </p>
                            <p class="small text-body-secondary mb-0">
                                These are copied from the current monthly and yearly prices in the main currency (<?php echo adminEscape($pricesCurrencies['default']); ?>)
                                each time a price is saved here. Customers are not charged while charging is off.
                            </p>
                        </div>
                    </div>

                    <?php if ($pricesGroups === []) { ?>
                    <div class="alert alert-info" role="status">
                        <i class="fa-solid fa-circle-info me-1" aria-hidden="true"></i>
                        Pay-as-you-go pack prices are set on the pay-as-you-go screen, which comes later.
                    </div>
                    <?php } ?>

                    <?php foreach ($pricesGroups as $pricesGroupKey => $pricesGroup) { ?>
                    <?php
                    $pricesCurrent = adminPlansCurrentByCurrency($pricesRows, $pricesGroupKey);
                    $pricesIsErrorGroup = ($pricesErrorGroup === $pricesGroupKey);
                    $pricesIdPrefix = 'f-' . $pricesGroupKey . '-';

                    // 🗓️ The group's dates and tick, from its first current price.
                    $pricesFirst = null;
                    foreach ($pricesCurrent as $pricesCurrencyRows) {
                        if ($pricesFirst === null || (int) $pricesCurrencyRows[0]['pricePointID'] < (int) $pricesFirst['pricePointID']) {
                            $pricesFirst = $pricesCurrencyRows[0];
                        }
                    }
                    $pricesFrom = ['date' => '', 'time' => ''];
                    $pricesUntil = ['date' => '', 'time' => ''];
                    $pricesRecommended = false;
                    if (is_array($pricesFirst)) {
                        $pricesFromStored = null;
                        if ($pricesFirst['validFrom'] !== null) {
                            $pricesFromStored = (string) $pricesFirst['validFrom'];
                        }
                        $pricesUntilStored = null;
                        if ($pricesFirst['validUntil'] !== null) {
                            $pricesUntilStored = (string) $pricesFirst['validUntil'];
                        }
                        $pricesFrom = adminPlansUtcToInputs($pricesFromStored, $pricesTimezone, false);
                        $pricesUntil = adminPlansUtcToInputs($pricesUntilStored, $pricesTimezone, true);
                    }
                    foreach ($pricesCurrent as $pricesCurrencyRows) {
                        if ((int) $pricesCurrencyRows[0]['isDefault'] === 1) {
                            $pricesRecommended = true;
                        }
                    }
                    $pricesBoxValues = [
                        'valid_from_date'  => $pricesFrom['date'],
                        'valid_from_time'  => $pricesFrom['time'],
                        'valid_until_date' => $pricesUntil['date'],
                        'valid_until_time' => $pricesUntil['time'],
                    ];
                    $pricesFormFp = $pricesFp;
                    if ($pricesIsErrorGroup) {
                        foreach ($pricesBoxValues as $pricesBox => $pricesBoxValue) {
                            if (isset($pricesPosted[$pricesBox])) {
                                $pricesBoxValues[$pricesBox] = $pricesPosted[$pricesBox];
                            }
                        }
                        $pricesRecommended = (isset($pricesPosted['recommended']) && $pricesPosted['recommended'] === '1');
                        $pricesFormFp = $pricesPostedFp;
                    }
                    $pricesBoxError = static function (string $box) use ($pricesIsErrorGroup, $pricesErrors): string {
                        if ($pricesIsErrorGroup && isset($pricesErrors[$box])) {
                            return (string) $pricesErrors[$box];
                        }
                        return '';
                    };
                    ?>
                    <div class="card shadow-sm mb-4" id="group-<?php echo adminEscape($pricesGroupKey); ?>">
                        <div class="card-header bg-transparent"><h2 class="h5 mb-0"><?php echo adminEscape($pricesGroup['label']); ?> price</h2></div>
                        <div class="card-body">
                            <form method="POST" action="<?php echo adminEscape($pricesSelfPath); ?>" novalidate data-admin-dirty-check>
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="price_action" value="save_group">
                                <input type="hidden" name="group" value="<?php echo adminEscape($pricesGroupKey); ?>">
                                <input type="hidden" name="fp" value="<?php echo adminEscape($pricesFormFp); ?>">
                                <fieldset <?php if (!$pricesCanEdit) { ?>disabled<?php } ?>>
                                    <legend class="visually-hidden"><?php echo adminEscape($pricesGroup['label']); ?> price</legend>

                                    <fieldset class="border rounded p-3 mb-3">
                                        <legend class="h6 float-none w-auto px-2">Amount in each currency</legend>
                                        <p class="small text-body-secondary">Digits and a point only, no symbol or commas (for example 12.50). Leave a box empty if there is no price in that currency yet. Prices include VAT when the VAT setting says so.</p>
                                        <?php foreach ($pricesCurrencies['offered'] as $pricesCurrency) { ?>
                                        <?php
                                        $pricesCurrentRow = null;
                                        if (isset($pricesCurrent[$pricesCurrency][0])) {
                                            $pricesCurrentRow = $pricesCurrent[$pricesCurrency][0];
                                        }
                                        $pricesShown = '';
                                        $pricesHelp = 'No price in ' . $pricesCurrency . ' yet.';
                                        if (is_array($pricesCurrentRow)) {
                                            $pricesStoredMinor = moneyStoredDecimalToMinor((string) $pricesCurrentRow['amount'], $pricesCurrency);
                                            if ($pricesStoredMinor !== null) {
                                                $pricesShown = moneyMinorToDecimalString($pricesStoredMinor, $pricesCurrency);
                                            }
                                            $pricesUses = 0;
                                            if (isset($pricesReferences[(int) $pricesCurrentRow['pricePointID']])) {
                                                $pricesUses = (int) $pricesReferences[(int) $pricesCurrentRow['pricePointID']];
                                            }
                                            $pricesHelp = 'Not bought yet, so it can still be changed.';
                                            if (adminPlansPriceIsFrozen($pricesUses)) {
                                                $pricesHelp = 'Bought (' . $pricesUses . ' record(s)), so this amount is fixed: customers keep what they bought. To change it, retire it in the list below and then type the new price here.';
                                            }
                                        }
                                        if ($pricesIsErrorGroup && isset($pricesPosted['amount'][$pricesCurrency])) {
                                            $pricesShown = $pricesPosted['amount'][$pricesCurrency];
                                        }
                                        echo adminPlansFieldHtml([
                                            'id' => $pricesIdPrefix . 'amount_' . $pricesCurrency, 'name' => 'amount[' . $pricesCurrency . ']', 'kind' => 'money',
                                            'label' => $pricesCurrencyWords($pricesCurrency), 'value' => $pricesShown, 'maxLength' => 12,
                                            'help' => $pricesHelp, 'error' => $pricesBoxError('amount_' . $pricesCurrency),
                                        ]);
                                        ?>
                                        <?php } ?>
                                    </fieldset>

                                    <fieldset class="border rounded p-3 mb-3">
                                        <legend class="h6 float-none w-auto px-2">When this price is offered</legend>
                                        <p class="small text-body-secondary">For every currency above. <?php echo adminEscape(adminTimezoneLabel($pricesTimezone)); ?>; a date alone means the start of that day for "from" and the end of that day for "until". Leave empty for no limit.</p>
                                        <div class="row"><div class="col-sm-7">
                                            <?php echo adminPlansFieldHtml(['id' => $pricesIdPrefix . 'valid_from_date', 'name' => 'valid_from_date', 'label' => 'On sale from (date)', 'kind' => 'date', 'value' => $pricesBoxValues['valid_from_date'], 'error' => $pricesBoxError('valid_from_date')]); ?>
                                        </div><div class="col-sm-5">
                                            <?php echo adminPlansFieldHtml(['id' => $pricesIdPrefix . 'valid_from_time', 'name' => 'valid_from_time', 'label' => 'On sale from (time)', 'kind' => 'time', 'value' => $pricesBoxValues['valid_from_time'], 'help' => 'Optional.']); ?>
                                        </div></div>
                                        <div class="row"><div class="col-sm-7">
                                            <?php echo adminPlansFieldHtml(['id' => $pricesIdPrefix . 'valid_until_date', 'name' => 'valid_until_date', 'label' => 'On sale until (date)', 'kind' => 'date', 'value' => $pricesBoxValues['valid_until_date'], 'error' => $pricesBoxError('valid_until_date')]); ?>
                                        </div><div class="col-sm-5">
                                            <?php echo adminPlansFieldHtml(['id' => $pricesIdPrefix . 'valid_until_time', 'name' => 'valid_until_time', 'label' => 'On sale until (time)', 'kind' => 'time', 'value' => $pricesBoxValues['valid_until_time'], 'help' => 'Optional.']); ?>
                                        </div></div>
                                    </fieldset>

                                    <div class="form-check mb-3">
                                        <input type="hidden" name="recommended" value="0">
                                        <input class="form-check-input" type="checkbox" id="<?php echo adminEscape($pricesIdPrefix . 'recommended'); ?>" name="recommended" value="1" aria-describedby="<?php echo adminEscape($pricesIdPrefix . 'recommended-help'); ?>"<?php if ($pricesRecommended) { ?> checked<?php } ?>>
                                        <label class="form-check-label" for="<?php echo adminEscape($pricesIdPrefix . 'recommended'); ?>">Show this as the recommended choice</label>
                                        <div class="form-text" id="<?php echo adminEscape($pricesIdPrefix . 'recommended-help'); ?>">Only one price of a plan can be the recommended one; ticking this takes it off the others.</div>
                                    </div>
                                </fieldset>

                                <?php if ($pricesCanEdit) { ?>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fa-solid fa-floppy-disk me-1" aria-hidden="true"></i> Save <?php echo adminEscape(strtolower($pricesGroup['label'])); ?> price
                                </button>
                                <?php } ?>
                            </form>
                        </div>
                    </div>
                    <?php } ?>

                    <?php /* 🗂️ Every price the plan has ever had */ ?>
                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-transparent"><h2 class="h5 mb-0">All prices of this plan</h2></div>
                        <div class="card-body">
                            <?php if ($pricesRows === []) { ?>
                            <p class="mb-0 text-body-secondary">This plan has no prices yet.</p>
                            <?php } else { ?>
                            <ul class="list-group">
                                <?php foreach ($pricesRows as $pricesRow) { ?>
                                <?php
                                $pricesRowID = (int) $pricesRow['pricePointID'];
                                $pricesUses = 0;
                                if (isset($pricesReferences[$pricesRowID])) {
                                    $pricesUses = (int) $pricesReferences[$pricesRowID];
                                }
                                $pricesStatus = adminPlansPriceStatus($pricesRow, $pricesNowUtc);
                                $pricesStatusWords = [
                                    'on_sale'   => ['good', 'On sale'],
                                    'scheduled' => ['warning', 'Starts later'],
                                    'ended'     => ['warning', 'End date passed'],
                                    'retired'   => ['locked', 'Retired'],
                                ];
                                $pricesGroupWords = (string) $pricesRow['billingInterval'];
                                if ($pricesRow['priceGroupKey'] !== null && isset($pricesGroups[(string) $pricesRow['priceGroupKey']])) {
                                    $pricesGroupWords = $pricesGroups[(string) $pricesRow['priceGroupKey']]['label'];
                                }
                                $pricesRowName = $pricesAmountWords((string) $pricesRow['amount'], (string) $pricesRow['currency']) . ' ' . strtolower($pricesGroupWords);
                                $pricesFromWords = adminPlansDateWords($pricesRow['validFrom'], $pricesTimezone, 'no start date');
                                ?>
                                <li class="list-group-item">
                                    <div class="d-flex flex-wrap justify-content-between gap-2">
                                        <div>
                                            <p class="fw-bold mb-1"><?php echo adminEscape($pricesRowName); ?> <span class="fw-normal small text-body-secondary">(<?php echo adminEscape($pricesRow['currency']); ?>, key <code><?php echo adminEscape($pricesRow['priceKey']); ?></code>)</span></p>
                                            <p class="mb-1 small"><?php echo adminStatusHtml($pricesStatusWords[$pricesStatus][0], $pricesStatusWords[$pricesStatus][1]); ?>
                                                <?php if ((int) $pricesRow['isDefault'] === 1) { ?><span class="ms-2"><?php echo adminStatusHtml('neutral', 'Recommended'); ?></span><?php } ?>
                                                <?php if (!in_array((string) $pricesRow['currency'], $pricesCurrencies['offered'], true)) { ?><span class="ms-2"><?php echo adminStatusHtml('warning', 'Currency not offered any more'); ?></span><?php } ?>
                                            </p>
                                            <p class="mb-1 small text-body-secondary">
                                                From <?php echo adminEscape($pricesFromWords); ?>
                                                until <?php echo adminEscape(adminPlansDateWords($pricesRow['validUntil'], $pricesTimezone, 'no end date')); ?>.
                                            </p>
                                            <p class="mb-0 small">
                                                <?php if (adminPlansPriceIsFrozen($pricesUses)) { ?>
                                                <?php echo adminStatusHtml('locked', 'Bought (' . $pricesUses . ' record(s)) - the amount is fixed'); ?>
                                                <?php } else { ?>
                                                <?php echo adminStatusHtml('neutral', 'Not bought yet'); ?>
                                                <?php } ?>
                                            </p>
                                        </div>
                                        <div class="d-flex flex-column gap-2">
                                            <?php if ((int) $pricesRow['isActive'] === 1) { ?>
                                            <form method="POST" action="<?php echo adminEscape($pricesSelfPath); ?>">
                                                <?php echo csrfField(); ?>
                                                <input type="hidden" name="price_action" value="retire">
                                                <input type="hidden" name="price_id" value="<?php echo $pricesRowID; ?>">
                                                <input type="hidden" name="fp" value="<?php echo adminEscape($pricesFp); ?>">
                                                <div class="form-check small">
                                                    <input class="form-check-input" type="checkbox" id="f-confirm_retire_<?php echo $pricesRowID; ?>" name="confirm" value="1" <?php if (!$pricesCanEdit) { ?>disabled<?php } ?>>
                                                    <label class="form-check-label" for="f-confirm_retire_<?php echo $pricesRowID; ?>">Stop offering this price</label>
                                                </div>
                                                <button type="submit" class="btn btn-sm btn-outline-danger" <?php if (!$pricesCanEdit) { ?>disabled<?php } ?>>
                                                    Retire<span class="visually-hidden"> <?php echo adminEscape($pricesRowName); ?></span>
                                                </button>
                                            </form>
                                            <?php } ?>
                                            <?php if (!adminPlansPriceIsFrozen($pricesUses)) { ?>
                                            <form method="POST" action="<?php echo adminEscape($pricesSelfPath); ?>">
                                                <?php echo csrfField(); ?>
                                                <input type="hidden" name="price_action" value="delete">
                                                <input type="hidden" name="price_id" value="<?php echo $pricesRowID; ?>">
                                                <input type="hidden" name="fp" value="<?php echo adminEscape($pricesFp); ?>">
                                                <div class="form-check small">
                                                    <input class="form-check-input" type="checkbox" id="f-confirm_delete_<?php echo $pricesRowID; ?>" name="confirm" value="1" <?php if (!$pricesCanEdit) { ?>disabled<?php } ?>>
                                                    <label class="form-check-label" for="f-confirm_delete_<?php echo $pricesRowID; ?>">Delete it for good</label>
                                                </div>
                                                <button type="submit" class="btn btn-sm btn-outline-danger" <?php if (!$pricesCanEdit) { ?>disabled<?php } ?>>
                                                    Delete<span class="visually-hidden"> <?php echo adminEscape($pricesRowName); ?></span>
                                                </button>
                                            </form>
                                            <?php } ?>
                                        </div>
                                    </div>
                                </li>
                                <?php } ?>
                            </ul>
                            <?php } ?>
                        </div>
                    </div>
                    <?php } ?>
                </div>
            </div>
        </div>
    </section>

<?php
require_once CUERCODE_INCLUDES_DIR . 'footer.php';
