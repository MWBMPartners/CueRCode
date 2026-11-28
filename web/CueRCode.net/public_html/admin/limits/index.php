<?php
/**
 * ==============================================================================
 * 🎚️ CueRCode - Admin: one person's own dynamic-code limit (Global Admins only)
 * ==============================================================================
 *
 * Path: public_html/admin/limits/index.php
 *
 * WHAT IT IS (plain English):
 *   Everyone may make their plan's number of dynamic QR codes (3 on Free). A
 *   Global Admin can approve more for one named person (decision 0031). On
 *   this page they can:
 *     - SEE everyone who has their own number, with the reason;
 *     - FIND one person by their exact email address and see their plan's
 *       number, their own number, the number that applies and how many codes
 *       count against it;
 *     - SET their own number (with a written reason) or REMOVE it.
 *   Decision 0045: the own number only ever RAISES the plan's number (the
 *   person gets the higher of the two) and is ignored while a payment is
 *   overdue. The saving is adminSetUserLimitOverride() (fn_admin_limits.php),
 *   which writes the admin change log in the same transaction.
 *
 * Safety: requireGlobalAdminPage() first; every POST checks the CSRF token
 * and the same-site Origin; a save also needs a fresh password confirmation.
 * An email address is never put in an address bar: finding is a POST, and the
 * page then shows the person by their account number (?person=<number>).
 *
 * @package     CueRCode
 * @subpackage  Admin
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-28 (WP4c, #166)
 * @see         _functions/fn_admin_limits.php
 * @see         docs/decisions/0031, 0045
 */

// ==============================================================================
// 🚀 Load the application, then the admin functions, then the gate - FIRST
// ==============================================================================
require_once __DIR__ . '/../../../_includes/bootstrap.php';
require_once CUERCODE_FUNCTIONS_DIR . 'fn_admin.php';

$adminUser = requireGlobalAdminPage('/admin/limits/');

require_once CUERCODE_FUNCTIONS_DIR . 'fn_admin_limits.php';
require_once CUERCODE_FUNCTIONS_DIR . 'fn_api_redirects.php';

$limitsMessages = [];
$limitsKey      = 'dynamic_codes';

// ==============================================================================
// 📤 POST: find a person, or save their own number
// ==============================================================================
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $limitsAction = '';
    if (isset($_POST['limits_action']) && is_string($_POST['limits_action'])) {
        $limitsAction = $_POST['limits_action'];
    }

    // 🛡️ CSRF and same site always; a fresh password only for a save.
    $limitsCheck = adminCheckPost($adminUser, ($limitsAction === 'save'));

    if (!$limitsCheck['ok']) {
        if ($limitsCheck['reason'] !== 'reauth') {
            http_response_code(403);
        }
        $limitsMessages[] = ['type' => 'danger', 'text' => $limitsCheck['message']];
    } elseif ($limitsAction === 'find') {
        $limitsTyped = '';
        if (isset($_POST['email']) && is_string($_POST['email'])) {
            $limitsTyped = $_POST['email'];
        }
        $limitsFound = adminLimitsFindUserIdByEmail($limitsTyped);
        if ($limitsFound === null) {
            $limitsMessages[] = ['type' => 'warning', 'text' => 'No account has exactly that email address.'];
        } else {
            adminRedirect('/admin/limits/?person=' . $limitsFound . '#person');
        }
    } elseif ($limitsAction === 'save') {
        $limitsPerson = 0;
        if (isset($_POST['person']) && is_string($_POST['person']) && preg_match('/^[1-9][0-9]{0,9}$/D', $_POST['person']) === 1) {
            $limitsPerson = (int) $_POST['person'];
        }
        $limitsValueText = '';
        if (isset($_POST['own_number']) && is_string($_POST['own_number'])) {
            $limitsValueText = $_POST['own_number'];
        }
        if (isset($_POST['remove']) && $_POST['remove'] === '1') {
            $limitsValueText = '';
        }
        $limitsReason = '';
        if (isset($_POST['reason']) && is_string($_POST['reason'])) {
            $limitsReason = $_POST['reason'];
        }

        $limitsChecked = adminUserLimitOverrideCheck($limitsKey, $limitsValueText, $limitsReason);
        if ($limitsPerson === 0) {
            $limitsMessages[] = ['type' => 'danger', 'text' => 'That person was not recognised, so nothing was saved.'];
        } elseif (!$limitsChecked['ok']) {
            $limitsMessages[] = ['type' => 'danger', 'text' => $limitsChecked['error']];
        } else {
            $limitsSaved = adminSetUserLimitOverride($adminUser, $limitsPerson, $limitsKey, $limitsChecked['value'], $limitsChecked['reason']);
            if ($limitsSaved['ok']) {
                adminReauthTouch($adminUser);
                adminFlashAdd('success', $limitsSaved['message']);
                adminRedirect('/admin/limits/?person=' . $limitsPerson . '#person');
            }
            $limitsMessages[] = ['type' => 'danger', 'text' => $limitsSaved['message']];
        }
    } else {
        $limitsMessages[] = ['type' => 'danger', 'text' => 'That request was not understood, so nothing was saved.'];
    }
}

// ==============================================================================
// 📥 What the page shows
// ==============================================================================
$limitsPersonId = 0;
if (isset($_GET['person']) && is_string($_GET['person']) && preg_match('/^[1-9][0-9]{0,9}$/D', $_GET['person']) === 1) {
    $limitsPersonId = (int) $_GET['person'];
}
if ($limitsPersonId === 0 && isset($_POST['person']) && is_string($_POST['person']) && preg_match('/^[1-9][0-9]{0,9}$/D', $_POST['person']) === 1) {
    $limitsPersonId = (int) $_POST['person'];
}

$limitsPerson = null;
if ($limitsPersonId > 0) {
    $limitsPerson = adminLimitsFindPerson($limitsPersonId, $limitsKey);
    if ($limitsPerson === null) {
        $limitsMessages[] = ['type' => 'warning', 'text' => 'That person was not found.'];
    }
}

$limitsList       = adminLimitsListOverrides($limitsKey);
$limitsCanEdit    = adminReauthIsCurrent($adminUser);
$limitsReady      = adminTableExists('tblUserLimitOverrides');
$limitsTimezone   = adminDisplayTimezone();
$limitsTimezoneTx = adminTimezoneLabel($limitsTimezone);

// ==============================================================================
// 📄 Page variables
// ==============================================================================
$pageTitle       = 'People\'s own limits';
$pageDescription = 'Private screen for CueRCode Global Admins: approve more dynamic codes for one person.';
$pageNoIndex     = true;
$bodyClass       = 'page-admin page-admin-limits';
$pageCSS         = [];
$pageJS          = ['/assets/js/cuercode-admin.js'];

$adminActive    = 'limits';
$limitsMessages = array_merge(adminFlashTake(), $limitsMessages);

require_once CUERCODE_INCLUDES_DIR . 'header.php';
?>

    <section class="py-5">
        <div class="container">
            <div class="row">
                <div class="col-lg-3">
                    <?php require_once CUERCODE_INCLUDES_DIR . 'admin_nav.php'; ?>
                </div>

                <div class="col-lg-9">
                    <h1 class="h2 fw-bold mb-3">People's own limits</h1>
                    <p class="text-body-secondary mb-4">
                        Everyone may make their plan's number of dynamic QR codes. Here you can approve more for one person.
                        Their own number only ever raises their plan's number (they get the higher of the two), and it is
                        ignored while a payment is overdue. Every change needs a reason and is kept in the admin change log.
                    </p>

                    <?php echo adminRenderMessages($limitsMessages); ?>

                    <?php if (!$limitsReady) { ?>
                    <div class="card shadow-sm mb-4">
                        <div class="card-body">
                            <p class="mb-2"><?php echo adminStatusHtml('warning', 'Database upgrade needed - own limits cannot be set until the waiting upgrades are applied.'); ?></p>
                            <a href="/admin/upgrades/">See database upgrades</a>
                        </div>
                    </div>
                    <?php } ?>

                    <?php /* 🔎 Find a person */ ?>
                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-transparent">
                            <h2 class="h5 mb-0"><i class="fa-solid fa-magnifying-glass me-2" aria-hidden="true"></i>Find a person</h2>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="/admin/limits/" novalidate>
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="limits_action" value="find">
                                <div class="mb-3">
                                    <label class="form-label" for="limits-email">Their email address (exactly as they signed up)</label>
                                    <input class="form-control" type="email" id="limits-email" name="email" maxlength="255" autocomplete="off" required>
                                </div>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fa-solid fa-magnifying-glass me-1" aria-hidden="true"></i> Find
                                </button>
                            </form>
                        </div>
                    </div>

                    <?php if ($limitsPerson !== null) { ?>
                    <?php
                    $limitsOwnText = 'None (the plan\'s number applies)';
                    $limitsOwnValue = '';
                    if ($limitsPerson['own'] !== null) {
                        $limitsOwnValue = (string) (int) $limitsPerson['own']['limitValue'];
                        $limitsOwnText  = adminLimitsNumberText((int) $limitsPerson['own']['limitValue']);
                    }
                    $limitsHeldText = 'Not known';
                    if ($limitsPerson['held'] !== null) {
                        $limitsHeldText = (string) (int) $limitsPerson['held'];
                    }
                    ?>
                    <div class="card shadow-sm mb-4" id="person">
                        <div class="card-header bg-transparent">
                            <h2 class="h5 mb-0"><i class="fa-solid fa-user me-2" aria-hidden="true"></i><?php echo adminEscape($limitsPerson['user']['email']); ?></h2>
                        </div>
                        <div class="card-body">
                            <dl class="row mb-3">
                                <dt class="col-sm-5">Account number</dt>
                                <dd class="col-sm-7"><?php echo (int) $limitsPerson['user']['userID']; ?></dd>
                                <dt class="col-sm-5">Account status</dt>
                                <dd class="col-sm-7"><?php echo adminEscape($limitsPerson['user']['status']); ?><?php if ($limitsPerson['user']['emailVerifiedAt'] === null) { ?> (email not confirmed - they cannot make dynamic codes yet)<?php } ?></dd>
                                <dt class="col-sm-5">Plan</dt>
                                <dd class="col-sm-7"><?php echo adminEscape($limitsPerson['planName']); ?><?php if ($limitsPerson['pastDue']) { ?> (payment overdue - their own number is ignored until it is paid)<?php } ?></dd>
                                <dt class="col-sm-5">Plan's number of dynamic codes</dt>
                                <dd class="col-sm-7"><?php echo adminEscape(adminLimitsNumberText((int) $limitsPerson['planNumber'])); ?></dd>
                                <dt class="col-sm-5">Their own number</dt>
                                <dd class="col-sm-7"><?php echo adminEscape($limitsOwnText); ?></dd>
                                <?php if ($limitsPerson['own'] !== null) { ?>
                                <dt class="col-sm-5">Reason</dt>
                                <dd class="col-sm-7 text-break"><?php echo adminEscape((string) $limitsPerson['own']['reason']); ?></dd>
                                <dt class="col-sm-5">Last set</dt>
                                <dd class="col-sm-7">
                                    <?php echo adminEscape(adminFormatUtcForDisplay((string) $limitsPerson['own']['updatedAt'], $limitsTimezone)); ?>
                                    (<?php echo adminEscape($limitsTimezoneTx); ?>)<?php if ($limitsPerson['own']['setByEmail'] !== null) { ?>, by <?php echo adminEscape($limitsPerson['own']['setByEmail']); ?><?php } ?>
                                </dd>
                                <?php } ?>
                                <dt class="col-sm-5">Number that applies now</dt>
                                <dd class="col-sm-7 fw-semibold"><?php echo adminEscape(adminLimitsNumberText((int) $limitsPerson['applies'])); ?></dd>
                                <dt class="col-sm-5">Dynamic codes that count now</dt>
                                <dd class="col-sm-7"><?php echo adminEscape($limitsHeldText); ?> (every code except one that was never made; switched-off codes count)</dd>
                            </dl>

                            <?php if (!$limitsReady) { ?>
                                <p class="mb-0"><?php echo adminStatusHtml('warning', 'Apply the waiting database upgrades first.'); ?></p>
                            <?php } elseif ($limitsCanEdit) { ?>
                            <form method="POST" action="/admin/limits/?person=<?php echo (int) $limitsPerson['user']['userID']; ?>" novalidate>
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="limits_action" value="save">
                                <input type="hidden" name="person" value="<?php echo (int) $limitsPerson['user']['userID']; ?>">
                                <div class="mb-3">
                                    <label class="form-label" for="limits-number">Their own number of dynamic codes</label>
                                    <input class="form-control" type="text" inputmode="numeric" id="limits-number" name="own_number" maxlength="8"
                                           value="<?php echo adminEscape($limitsOwnValue); ?>" aria-describedby="limits-number-help">
                                    <div class="form-text" id="limits-number-help">
                                        A whole number from 1 to <?php echo adminEscape(number_format(CUERCODE_ADMIN_LIMIT_OVERRIDE_MAX)); ?>, or -1 for no limit.
                                        Leave it empty to use the plan's number again. A number at or below the plan's number changes nothing.
                                    </div>
                                </div>
                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="checkbox" value="1" id="limits-remove" name="remove">
                                    <label class="form-check-label" for="limits-remove">Remove their own number (the plan's number applies again)</label>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label" for="limits-reason">Reason (required)</label>
                                    <input class="form-control" type="text" id="limits-reason" name="reason" maxlength="500" required aria-describedby="limits-reason-help">
                                    <div class="form-text" id="limits-reason-help">One line, up to 500 characters. It is kept in the admin change log.</div>
                                </div>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fa-solid fa-floppy-disk me-1" aria-hidden="true"></i> Save their own number
                                </button>
                            </form>
                            <?php } else { ?>
                            <p class="mb-3"><?php echo adminStatusHtml('locked', 'Editing is locked. Confirm your password to change this person\'s number.'); ?></p>
                            <a class="btn btn-primary" href="/admin/reauth/?next=<?php echo rawurlencode('/admin/limits/?person=' . (int) $limitsPerson['user']['userID']); ?>">
                                <i class="fa-solid fa-unlock me-1" aria-hidden="true"></i> Unlock editing - confirm your password
                            </a>
                            <?php } ?>
                        </div>
                    </div>
                    <?php } ?>

                    <?php /* 📋 Everyone with their own number */ ?>
                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-transparent">
                            <h2 class="h5 mb-0"><i class="fa-solid fa-list me-2" aria-hidden="true"></i>People with their own number</h2>
                        </div>
                        <div class="card-body">
                            <?php if ($limitsList === null) { ?>
                                <p class="mb-0"><?php echo adminStatusHtml('warning', 'The list could not be read just now. Please reload the page.'); ?></p>
                            <?php } elseif ($limitsList === []) { ?>
                                <p class="mb-0 text-body-secondary">Nobody has their own number yet: everyone has their plan's number.</p>
                            <?php } else { ?>
                            <ul class="list-unstyled mb-0">
                                <?php foreach ($limitsList as $limitsRow) { ?>
                                <li class="mb-3">
                                    <a class="fw-semibold text-break" href="/admin/limits/?person=<?php echo (int) $limitsRow['userID']; ?>#person"><?php echo adminEscape(adminMaskEmail((string) $limitsRow['email'])); ?></a>
                                    (account <?php echo (int) $limitsRow['userID']; ?>):
                                    <?php echo adminEscape(adminLimitsNumberText((int) $limitsRow['limitValue'])); ?>
                                    <br><span class="small text-body-secondary text-break">
                                        <?php echo adminEscape((string) $limitsRow['reason']); ?> -
                                        <?php echo adminEscape(adminFormatUtcForDisplay((string) $limitsRow['updatedAt'], $limitsTimezone)); ?>
                                        (<?php echo adminEscape($limitsTimezoneTx); ?>)
                                    </span>
                                </li>
                                <?php } ?>
                            </ul>
                            <?php } ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

<?php
require_once CUERCODE_INCLUDES_DIR . 'footer.php';
