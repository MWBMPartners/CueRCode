<?php
/**
 * ==============================================================================
 * ➕ CueRCode - Admin: Add a plan (Global Admins only)
 * ==============================================================================
 *
 * Path: public_html/admin/plans/new/index.php
 *
 * Makes a new plan (task 7n Phase 2, plan §7.1): key and kind (fixed for
 * ever once made), name, description, display order, button, sale dates,
 * cap, price promise, and which plan to copy features and limits from. The
 * new plan starts OFF SALE with no prices; the page then goes to its prices.
 *
 * 🔒 The POST goes, in this order: CSRF token, same-site (Origin) check,
 *    fresh password confirmation, database ready, then fn_admin_plans.php
 *    checks every box and saves the plan and its audit row in ONE
 *    transaction, then a redirect (a reload cannot send it twice).
 *
 * 🚪 requireGlobalAdminPage() is the first application call (a test checks
 *    this for every admin page).
 *
 * @package     CueRCode
 * @subpackage  Admin
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-26 (task 7n Phase 2, slice 2c - #31)
 * @see         _functions/fn_admin_plans.php - adminPlansCreate()
 * @see         .claude/plans/7n-admin-commerce-plan.md §7.1, §7.5
 */

// ==============================================================================
// 🚀 Load the application, then the admin functions, then the gate - FIRST
// ==============================================================================
require_once __DIR__ . '/../../../../_includes/bootstrap.php';
require_once CUERCODE_FUNCTIONS_DIR . 'fn_admin.php';

$adminUser = requireGlobalAdminPage('/admin/plans/new/');

// 💳 The plans editor itself (loaded after the gate).
require_once CUERCODE_FUNCTIONS_DIR . 'fn_admin_plans.php';

// ==============================================================================
// 📋 State
// ==============================================================================
$newTimezone    = adminDisplayTimezone();
$newReady       = adminDatabaseReadyForChanges();
$newSecondsLeft = adminReauthSecondsLeftForAdmin($adminUser);
$newCanEdit     = ($newReady && $newSecondsLeft > 0);

$newMessages = [];
$newErrors   = [];

$newPlans = null;
if ($newReady) {
    $newPlans = adminPlansLoadAllPlans();
}

// 🔢 Suggested display order: after the last plan.
$newNextOrder = 1;
if (is_array($newPlans)) {
    foreach ($newPlans as $newPlanRow) {
        if ((int) $newPlanRow['sortOrder'] >= $newNextOrder) {
            $newNextOrder = (int) $newPlanRow['sortOrder'] + 1;
        }
    }
}
if ($newNextOrder > CUERCODE_ADMIN_PLAN_SORT_MAX) {
    $newNextOrder = CUERCODE_ADMIN_PLAN_SORT_MAX;
}

$newValues = [
    'plan_key'   => '',
    'plan_kind'  => 'standard',
    'copy_from'  => '',
    'plan_name'  => '',
    'sort_order' => (string) $newNextOrder,
    'cta_mode'   => 'checkout',
    'promise_mode' => '',
];

// ==============================================================================
// 📤 POST: make the plan
// ==============================================================================
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // 🛡️ CSRF → same site → fresh password (plan §6.3, §6.4).
    $newCheck = adminCheckPost($adminUser, true);

    if (!$newCheck['ok']) {
        if ($newCheck['reason'] !== 'reauth') {
            http_response_code(403);
        }
        $newMessages[] = ['type' => 'danger', 'text' => $newCheck['message']];
    } elseif (!$newReady) {
        $newMessages[] = ['type' => 'danger', 'text' => 'Database upgrade needed, so nothing was saved. Apply the waiting upgrades on the database upgrades page first.'];
    } else {
        $newResult = adminPlansCreate($adminUser, $_POST);
        if ($newResult['ok']) {
            adminReauthTouch($adminUser);
            adminFlashAdd('success', $newResult['message']);
            adminRedirect('/admin/plans/prices/?id=' . (int) $newResult['planID']);
        }
        $newErrors = $newResult['errors'];
        if ($newErrors === []) {
            $newMessages[] = ['type' => 'danger', 'text' => $newResult['message']];
        }
    }

    // 📋 Show what was typed again (there are no secrets on this form).
    foreach (array_keys(adminPlansFormLabels()) as $newBox) {
        if (isset($_POST[$newBox]) && is_string($_POST[$newBox])) {
            $newValues[$newBox] = $_POST[$newBox];
        }
    }

    $newSecondsLeft = adminReauthSecondsLeftForAdmin($adminUser);
    $newCanEdit     = ($newReady && $newSecondsLeft > 0);
}

// 📋 The plans that can be copied from.
$newCopyChoices = [];
if (is_array($newPlans)) {
    foreach ($newPlans as $newPlanRow) {
        $newCopyChoices[(int) $newPlanRow['planID']] = (string) $newPlanRow['planName'] . ' (' . (string) $newPlanRow['planKey'] . ')';
    }
}

// ==============================================================================
// 📄 Page variables
// ==============================================================================
$pageTitle       = 'Add a plan';
$pageDescription = 'Private plan editor for CueRCode Global Admins.';
$pageNoIndex     = true;
$bodyClass       = 'page-admin page-admin-plans';
$pageCSS         = [];
$pageJS          = ['/assets/js/cuercode-admin.js'];

$adminActive = 'plans';
$newMessages = array_merge(adminFlashTake(), $newMessages);

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
                    <h1 class="h2 fw-bold mb-3">Add a plan</h1>
                    <p class="text-body-secondary mb-4">
                        The new plan starts off sale and with no prices, so nothing changes for visitors until you add its prices
                        and put it on sale.
                    </p>

                    <?php echo adminRenderMessages($newMessages); ?>
                    <?php echo adminPlansErrorSummaryHtml($newErrors, adminPlansFormLabels(), 'f-'); ?>
                    <?php echo adminPlansEditStateHtml($newReady, $newSecondsLeft, time(), $newTimezone, '/admin/plans/new/'); ?>

                    <?php if ($newReady && $newPlans === null) { ?>
                    <div class="alert alert-danger" role="alert">
                        <i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i>
                        The plans could not be read just now. Please reload the page.
                    </div>
                    <?php } elseif ($newReady) { ?>
                    <div class="card shadow-sm">
                        <div class="card-body">
                            <form method="POST" action="/admin/plans/new/" novalidate data-admin-dirty-check>
                                <?php echo csrfField(); ?>
                                <fieldset <?php if (!$newCanEdit) { ?>disabled<?php } ?>>
                                    <legend class="visually-hidden">New plan details</legend>
                                    <?php echo adminPlansFormFieldsHtml($newValues, $newErrors, true, $newCopyChoices, $newTimezone); ?>
                                </fieldset>
                                <?php if ($newCanEdit) { ?>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fa-solid fa-plus me-1" aria-hidden="true"></i> Make the plan
                                </button>
                                <?php } ?>
                            </form>
                        </div>
                    </div>
                    <?php } ?>
                </div>
            </div>
        </div>
    </section>

<?php
require_once CUERCODE_INCLUDES_DIR . 'footer.php';
