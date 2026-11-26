<?php
/**
 * ==============================================================================
 * ✏️ CueRCode - Admin: Plan details (Global Admins only)
 * ==============================================================================
 *
 * Path: public_html/admin/plans/edit/index.php?id=<planID>
 *
 * One plan (task 7n Phase 2, plan §7.1): its details form (name,
 * description, display order, button, sale dates, cap, price promise), and
 * the actions: put on sale / take off sale, make a new version for new
 * customers, and delete (only while nothing has ever used it).
 *
 * 🧊 The freezing rules are enforced by fn_admin_plans.php on the server, not
 *    by what this page shows: P4 delete only when unused, P5 the fallback and
 *    setting-named plans can never go off sale, P6 the key and kind never
 *    change (they are not even boxes here, and a hand-made request that sends
 *    a different one is refused).
 *
 * 🔒 Every POST goes, in this order: CSRF token, same-site (Origin) check,
 *    fresh password confirmation, database ready, then ONE transaction that
 *    locks the plan, checks the form's fingerprint (someone else's change
 *    since the page was opened is never overwritten), saves and writes the
 *    audit row, then a redirect. Risky actions also need a tick that the
 *    SERVER checks (works with JavaScript off).
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
 * @see         .claude/plans/7n-admin-commerce-plan.md §4.3, §7.1, §7.5
 */

// ==============================================================================
// 🚀 Load the application, then the admin functions, then the gate - FIRST
// ==============================================================================
require_once __DIR__ . '/../../../../_includes/bootstrap.php';
require_once CUERCODE_FUNCTIONS_DIR . 'fn_admin.php';

$adminUser = requireGlobalAdminPage('/admin/plans/edit/');

// 💳 The plans editor itself (loaded after the gate).
require_once CUERCODE_FUNCTIONS_DIR . 'fn_admin_plans.php';

// ==============================================================================
// 📋 Which plan, and the editing state
// ==============================================================================
$editPlanID     = adminPlansIdFromQuery($_GET);
$editSelfPath   = '/admin/plans/edit/?id=' . $editPlanID;
$editTimezone   = adminDisplayTimezone();
$editReady      = adminDatabaseReadyForChanges();
$editSecondsLeft = adminReauthSecondsLeftForAdmin($adminUser);
$editCanEdit    = ($editReady && $editSecondsLeft > 0);

$editMessages = [];
$editErrors   = [];
$editPosted   = null;     // what was typed in the details form, after a refused save
$editPostedFp = '';

// ==============================================================================
// 📤 POST: one of the five actions
// ==============================================================================
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // 🛡️ CSRF → same site → fresh password (plan §6.3, §6.4).
    $editCheck = adminCheckPost($adminUser, true);

    $editAction = '';
    if (isset($_POST['plan_action']) && is_string($_POST['plan_action'])) {
        $editAction = $_POST['plan_action'];
    }
    $editFp = '';
    if (isset($_POST['fp']) && is_string($_POST['fp'])) {
        $editFp = $_POST['fp'];
    }
    $editConfirmed = (isset($_POST['confirm']) && $_POST['confirm'] === '1');

    $editResult = null;

    if (!$editCheck['ok']) {
        if ($editCheck['reason'] !== 'reauth') {
            http_response_code(403);
        }
        $editMessages[] = ['type' => 'danger', 'text' => $editCheck['message']];
    } elseif (!$editReady) {
        $editMessages[] = ['type' => 'danger', 'text' => 'Database upgrade needed, so nothing was saved. Apply the waiting upgrades on the database upgrades page first.'];
    } elseif ($editPlanID === 0) {
        $editMessages[] = ['type' => 'danger', 'text' => 'No plan was chosen, so nothing was saved.'];
    } elseif ($editAction === 'update') {
        $editResult = adminPlansUpdate($adminUser, $editPlanID, $editFp, $_POST);
        if (!$editResult['ok']) {
            $editPosted = [];
            foreach (array_keys(adminPlansFormLabels()) as $editBox) {
                if (isset($_POST[$editBox]) && is_string($_POST[$editBox])) {
                    $editPosted[$editBox] = $_POST[$editBox];
                }
            }
            // 🧾 The OLD fingerprint is kept, so pressing Save again is refused
            //    again until the page is reloaded (when the form was out of date).
            $editPostedFp = $editFp;
        }
    } elseif ($editAction === 'on_sale') {
        $editResult = adminPlansSetOnSale($adminUser, $editPlanID, $editFp, true, $editConfirmed);
    } elseif ($editAction === 'off_sale') {
        $editResult = adminPlansSetOnSale($adminUser, $editPlanID, $editFp, false, $editConfirmed);
    } elseif ($editAction === 'new_version') {
        $editNewKey = '';
        if (isset($_POST['new_key']) && is_string($_POST['new_key'])) {
            $editNewKey = $_POST['new_key'];
        }
        $editResult = adminPlansNewVersion($adminUser, $editPlanID, $editFp, $editNewKey, $editConfirmed);
        if ($editResult['ok'] && $editResult['changed']) {
            adminReauthTouch($adminUser);
            adminFlashAdd('success', $editResult['message']);
            adminRedirect('/admin/plans/edit/?id=' . (int) $editResult['planID']);
        }
    } elseif ($editAction === 'delete') {
        $editResult = adminPlansDelete($adminUser, $editPlanID, $editFp, $editConfirmed);
        if ($editResult['ok'] && $editResult['changed']) {
            adminReauthTouch($adminUser);
            adminFlashAdd('success', $editResult['message']);
            adminRedirect('/admin/plans/');
        }
    } else {
        $editMessages[] = ['type' => 'danger', 'text' => 'That request was not understood, so nothing was saved.'];
    }

    if (is_array($editResult)) {
        if ($editResult['ok']) {
            adminReauthTouch($adminUser);
            $editFlashType = 'info';
            if ($editResult['changed']) {
                $editFlashType = 'success';
            }
            adminFlashAdd($editFlashType, $editResult['message']);
            adminRedirect($editSelfPath);
        }
        $editErrors = $editResult['errors'];
        if ($editErrors === [] || $editResult['stale']) {
            $editMessages[] = ['type' => 'danger', 'text' => $editResult['message']];
        }
    }

    $editSecondsLeft = adminReauthSecondsLeftForAdmin($adminUser);
    $editCanEdit     = ($editReady && $editSecondsLeft > 0);
}

// ==============================================================================
// 📥 Read the plan and what refers to it (for the page; the saves re-check
//    everything inside their own locked transaction)
// ==============================================================================
$editPlan = null;
$editUsage = null;
$editSold = null;
$editProtected = '';
$editAllKeys = [];
$editReadProblem = false;

if ($editReady && $editPlanID > 0) {
    $editLoaded = adminPlansLoadPlan($editPlanID, false);
    if (!$editLoaded['ok']) {
        $editReadProblem = true;
    } else {
        $editPlan = $editLoaded['row'];
    }
}

if (is_array($editPlan)) {
    $editUsage = adminPlansUsage($editPlanID, false);
    $editSold = adminPlansSoldCount($editPlan, false);
    $editSettingKeys = adminPlansSettingPlanKeys(false);
    $editAllPlans = adminPlansLoadAllPlans();
    if ($editUsage === null || $editSold === null || $editSettingKeys === null || $editAllPlans === null) {
        $editReadProblem = true;
    } else {
        $editProtected = adminPlansProtectedReason((string) $editPlan['planKey'], $editSettingKeys);
        foreach ($editAllPlans as $editOtherPlan) {
            $editAllKeys[] = (string) $editOtherPlan['planKey'];
        }
    }
}

if ($editReady && !$editReadProblem && $editPlan === null) {
    http_response_code(404);
}

// ==============================================================================
// 📄 Page variables
// ==============================================================================
$editTitleName = 'Plan';
if (is_array($editPlan)) {
    $editTitleName = (string) $editPlan['planName'];
}
$pageTitle       = 'Plan details: ' . $editTitleName;
$pageDescription = 'Private plan editor for CueRCode Global Admins.';
$pageNoIndex     = true;
$bodyClass       = 'page-admin page-admin-plans';
$pageCSS         = [];
$pageJS          = ['/assets/js/cuercode-admin.js'];

$adminActive = 'plans';
$editMessages = array_merge(adminFlashTake(), $editMessages);

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

                    <?php if (!$editReady) { ?>
                    <h1 class="h2 fw-bold mb-3">Plan details</h1>
                    <?php echo adminPlansEditStateHtml(false, 0, time(), $editTimezone, $editSelfPath); ?>
                    <?php } elseif ($editReadProblem) { ?>
                    <h1 class="h2 fw-bold mb-3">Plan details</h1>
                    <div class="alert alert-danger" role="alert">
                        <i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i>
                        The plan could not be read just now. Please reload the page.
                    </div>
                    <?php } elseif ($editPlan === null) { ?>
                    <h1 class="h2 fw-bold mb-3">Plan not found</h1>
                    <?php echo adminRenderMessages($editMessages); ?>
                    <p>That plan does not exist (it may have been deleted). <a href="/admin/plans/">Go back to the list of plans</a>.</p>
                    <?php } else { ?>
                    <?php
                    $editFp = adminPlansFormFingerprint($editPlan);
                    $editFormFp = $editFp;
                    $editValues = adminPlansFormValuesFromRow($editPlan, $editTimezone);
                    if (is_array($editPosted)) {
                        $editValues = array_merge($editValues, $editPosted);
                        $editFormFp = $editPostedFp;
                    }
                    $editStatus = adminPlansSaleStatus($editPlan, adminPlansNowUtc());
                    $editDelete = adminPlansDeleteDecision($editUsage, $editProtected);
                    $editIsOnSale = ((int) $editPlan['isActive'] === 1);
                    $editHasPrices = (adminPlansPriceGroups((string) $editPlan['planKind']) !== []);
                    ?>
                    <h1 class="h2 fw-bold mb-1 text-break"><?php echo adminEscape($editPlan['planName']); ?></h1>
                    <p class="mb-3"><?php echo adminStatusHtml($editStatus['kind'], $editStatus['text']); ?>
                        <span class="ms-2 small text-body-secondary">Paying subscribers: <?php echo (int) $editUsage['paying']; ?></span>
                        <?php if ($editHasPrices) { ?>
                        <a class="ms-2 small" href="/admin/plans/prices/?id=<?php echo (int) $editPlanID; ?>">Prices of this plan</a>
                        <?php } ?>
                    </p>

                    <?php echo adminRenderMessages($editMessages); ?>
                    <?php echo adminPlansErrorSummaryHtml($editErrors, adminPlansFormLabels() + ['confirm_retire' => 'Confirm taking it off sale', 'confirm_delete' => 'Confirm deleting', 'confirm_version' => 'Confirm the new version', 'new_key' => 'Key for the new version'], 'f-'); ?>
                    <?php echo adminPlansEditStateHtml($editReady, $editSecondsLeft, time(), $editTimezone, $editSelfPath); ?>

                    <?php /* ✏️ The details */ ?>
                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-transparent"><h2 class="h5 mb-0">Details</h2></div>
                        <div class="card-body">
                            <?php if ((int) $editUsage['paying'] > 0) { ?>
                            <p class="small"><?php echo adminStatusHtml('warning', 'People pay for this plan. Changes here apply to the plan straight away; to change what is offered to new customers only, use "Make a new version" below.'); ?></p>
                            <?php } ?>
                            <form method="POST" action="<?php echo adminEscape($editSelfPath); ?>" novalidate data-admin-dirty-check>
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="plan_action" value="update">
                                <input type="hidden" name="fp" value="<?php echo adminEscape($editFormFp); ?>">
                                <fieldset <?php if (!$editCanEdit) { ?>disabled<?php } ?>>
                                    <legend class="visually-hidden">Plan details</legend>
                                    <?php echo adminPlansFormFieldsHtml($editValues, $editErrors, false, [], $editTimezone); ?>
                                </fieldset>
                                <?php if ($editCanEdit) { ?>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fa-solid fa-floppy-disk me-1" aria-hidden="true"></i> Save plan details
                                </button>
                                <?php } ?>
                            </form>
                        </div>
                    </div>

                    <?php /* 🏷️ On sale / off sale */ ?>
                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-transparent"><h2 class="h5 mb-0">On sale</h2></div>
                        <div class="card-body">
                            <?php if ($editIsOnSale) { ?>
                                <?php if ($editProtected !== '') { ?>
                                <p class="mb-0"><?php echo adminStatusHtml('locked', $editProtected); ?></p>
                                <?php } else { ?>
                                <p>Taking a plan off sale hides it from new customers only. Anyone already on it keeps it, and it keeps working for them.</p>
                                <form method="POST" action="<?php echo adminEscape($editSelfPath); ?>">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="plan_action" value="off_sale">
                                    <input type="hidden" name="fp" value="<?php echo adminEscape($editFp); ?>">
                                    <div class="form-check mb-3">
                                        <input class="form-check-input" type="checkbox" id="f-confirm_retire" name="confirm" value="1" <?php if (!$editCanEdit) { ?>disabled<?php } ?>>
                                        <label class="form-check-label" for="f-confirm_retire">I understand that new customers will no longer be able to choose <?php echo adminEscape($editPlan['planName']); ?>.</label>
                                    </div>
                                    <button type="submit" class="btn btn-outline-danger" <?php if (!$editCanEdit) { ?>disabled<?php } ?>>
                                        <i class="fa-solid fa-box-archive me-1" aria-hidden="true"></i> Take <?php echo adminEscape($editPlan['planName']); ?> off sale
                                    </button>
                                </form>
                                <?php } ?>
                            <?php } else { ?>
                                <p>This plan is off sale. Putting it on sale lets new customers choose it (once charging is switched on), and shows it on the pricing page if its display order is above 0.</p>
                                <form method="POST" action="<?php echo adminEscape($editSelfPath); ?>">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="plan_action" value="on_sale">
                                    <input type="hidden" name="fp" value="<?php echo adminEscape($editFp); ?>">
                                    <button type="submit" class="btn btn-outline-primary" <?php if (!$editCanEdit) { ?>disabled<?php } ?>>
                                        <i class="fa-solid fa-store me-1" aria-hidden="true"></i> Put <?php echo adminEscape($editPlan['planName']); ?> on sale
                                    </button>
                                </form>
                            <?php } ?>
                        </div>
                    </div>

                    <?php /* 🧬 New version */ ?>
                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-transparent"><h2 class="h5 mb-0">Make a new version for new customers</h2></div>
                        <div class="card-body">
                            <?php if ($editProtected !== '') { ?>
                            <p class="mb-0"><?php echo adminStatusHtml('locked', 'Not available: making a new version takes the old plan off sale. ' . $editProtected); ?></p>
                            <?php } else { ?>
                            <p>Copies this plan - details, features, limits and its current prices - under a new key, and takes this one off sale.
                                People already on this plan stay on it and keep exactly what they have. Use it when a change should only apply to new customers.</p>
                            <form method="POST" action="<?php echo adminEscape($editSelfPath); ?>">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="plan_action" value="new_version">
                                <input type="hidden" name="fp" value="<?php echo adminEscape($editFp); ?>">
                                <fieldset <?php if (!$editCanEdit) { ?>disabled<?php } ?>>
                                    <legend class="visually-hidden">New version</legend>
                                    <?php
                                    $editNewKeyError = '';
                                    if (isset($editErrors['new_key'])) {
                                        $editNewKeyError = $editErrors['new_key'];
                                    }
                                    echo adminPlansFieldHtml([
                                        'id' => 'f-new_key', 'name' => 'new_key', 'label' => 'Key for the new version', 'required' => true, 'maxLength' => 32,
                                        'value' => adminPlansNewVersionKey((string) $editPlan['planKey'], $editAllKeys),
                                        'help' => 'A suggestion is filled in. Lower-case letters, digits and underscores, starting with a letter.',
                                        'error' => $editNewKeyError,
                                    ]);
                                    ?>
                                    <div class="form-check mb-3">
                                        <input class="form-check-input" type="checkbox" id="f-confirm_version" name="confirm" value="1">
                                        <label class="form-check-label" for="f-confirm_version">I understand that <?php echo adminEscape($editPlan['planName']); ?> will be taken off sale and the copy offered instead.</label>
                                    </div>
                                </fieldset>
                                <button type="submit" class="btn btn-outline-primary" <?php if (!$editCanEdit) { ?>disabled<?php } ?>>
                                    <i class="fa-solid fa-code-branch me-1" aria-hidden="true"></i> Make a new version
                                </button>
                            </form>
                            <?php } ?>
                        </div>
                    </div>

                    <?php /* 🗑️ Delete */ ?>
                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-transparent"><h2 class="h5 mb-0">Delete</h2></div>
                        <div class="card-body">
                            <?php if (!$editDelete['allowed']) { ?>
                            <p class="mb-1">This plan cannot be deleted, because:</p>
                            <ul class="mb-1">
                                <?php foreach ($editDelete['reasons'] as $editReason) { ?>
                                <li><?php echo adminEscape($editReason); ?></li>
                                <?php } ?>
                            </ul>
                            <?php if ($editProtected === '') { ?>
                            <p class="small text-body-secondary mb-0">You can take it off sale instead.</p>
                            <?php } ?>
                            <?php } else { ?>
                            <p>Nothing has ever used this plan, so it can be deleted, together with its prices. This cannot be undone.</p>
                            <form method="POST" action="<?php echo adminEscape($editSelfPath); ?>">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="plan_action" value="delete">
                                <input type="hidden" name="fp" value="<?php echo adminEscape($editFp); ?>">
                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="checkbox" id="f-confirm_delete" name="confirm" value="1" <?php if (!$editCanEdit) { ?>disabled<?php } ?>>
                                    <label class="form-check-label" for="f-confirm_delete">I understand that <?php echo adminEscape($editPlan['planName']); ?> and its prices will be deleted for good.</label>
                                </div>
                                <button type="submit" class="btn btn-danger" <?php if (!$editCanEdit) { ?>disabled<?php } ?>>
                                    <i class="fa-solid fa-trash me-1" aria-hidden="true"></i> Delete <?php echo adminEscape($editPlan['planName']); ?>
                                </button>
                            </form>
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
