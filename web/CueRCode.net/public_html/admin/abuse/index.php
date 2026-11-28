<?php
/**
 * ==============================================================================
 * 📣 CueRCode - Admin: abuse reports and suspended dynamic codes (Global Admins only)
 * ==============================================================================
 *
 * Path: public_html/admin/abuse/index.php
 *
 * WHAT IT IS (plain English, WP4f #169):
 *   Anyone can report a dynamic QR code on /report/. Here a Global Admin can:
 *     - READ the reports still open (newest first): which code, what kind of
 *       harm, what the reporter wrote, their email if they gave one, and
 *       whether they passed the human check (only those count towards the
 *       automatic suspension);
 *     - MARK a report as being looked at, dealt with ("actioned") or
 *       dismissed - "actioned" and "dismissed" set its handled date, which
 *       starts the 12 months after which its personal details are removed
 *       (decision 0046);
 *     - SUSPEND a code by its short code, with a written reason;
 *     - LIFT a suspension, with a written reason (dynamicQrRestore(): the
 *       destination is checked again first, and Go2My.Link is changed first).
 *   Every change needs a fresh password confirmation and is written to the
 *   admin change log. The owner of a code can never lift a suspension.
 *   A destination is only ever shown as text here, never as a link.
 *
 * Safety: requireGlobalAdminPage() first; every POST checks the CSRF token and
 * the same-site Origin, and a fresh password (adminCheckPost()).
 *
 * @package     CueRCode
 * @subpackage  Admin
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-28 (WP4f, #169)
 * @see         _functions/fn_abuse_reports.php
 * @see         _functions/fn_dynamic_qr_takedown.php - dynamicQrSuspend(), dynamicQrRestore()
 * @see         docs/decisions/0034, 0046
 */

// ==============================================================================
// 🚀 Load the application, then the admin functions, then the gate - FIRST
// ==============================================================================
require_once __DIR__ . '/../../../_includes/bootstrap.php';
require_once CUERCODE_FUNCTIONS_DIR . 'fn_admin.php';

$adminUser = requireGlobalAdminPage('/admin/abuse/');

require_once CUERCODE_FUNCTIONS_DIR . 'fn_dynamic_qr_takedown.php';
require_once CUERCODE_FUNCTIONS_DIR . 'fn_abuse_reports.php';

$abuseMessages = [];

// ==============================================================================
// 📤 POST: suspend, lift a suspension, or change a report's status
// ==============================================================================
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $abuseAction = '';
    if (isset($_POST['abuse_action']) && is_string($_POST['abuse_action'])) {
        $abuseAction = $_POST['abuse_action'];
    }
    $abuseReason = '';
    if (isset($_POST['reason']) && is_string($_POST['reason'])) {
        $abuseReason = $_POST['reason'];
    }

    // 🛡️ CSRF, same site, and a fresh password for every change.
    $abuseCheck = adminCheckPost($adminUser, true);

    if (!$abuseCheck['ok']) {
        if ($abuseCheck['reason'] !== 'reauth') {
            http_response_code(403);
        }
        $abuseMessages[] = ['type' => 'danger', 'text' => $abuseCheck['message']];
    } elseif ($abuseAction === 'suspend') {
        $abuseTyped = '';
        if (isset($_POST['code']) && is_string($_POST['code'])) {
            $abuseTyped = $_POST['code'];
        }
        $abuseTarget = abuseFindRedirectIdByCode($abuseTyped);
        if ($abuseTarget === null) {
            $abuseMessages[] = ['type' => 'warning', 'text' => 'No dynamic code has that code, so nothing was suspended.'];
        } else {
            $abuseDone = dynamicQrSuspend($abuseTarget, ['type' => 'admin', 'admin' => $adminUser], $abuseReason, ['source' => 'admin']);
            if ($abuseDone['ok'] === true) {
                adminReauthTouch($adminUser);
                $abuseNote = $abuseDone['message'];
                if ($abuseDone['outcome'] === 'suspended' && $abuseDone['applied'] !== 'applied' && $abuseDone['applied'] !== 'not_needed') {
                    $abuseNote .= ' Go2My.Link has not confirmed it yet; it is sent again automatically every minute.';
                }
                adminFlashAdd('success', $abuseNote);
                adminRedirect('/admin/abuse/#suspended');
            }
            $abuseMessages[] = ['type' => 'danger', 'text' => $abuseDone['message']];
        }
    } elseif ($abuseAction === 'restore') {
        $abuseRedirect = 0;
        if (isset($_POST['redirect']) && is_string($_POST['redirect']) && preg_match('/^[1-9][0-9]{0,18}$/D', $_POST['redirect']) === 1) {
            $abuseRedirect = (int) $_POST['redirect'];
        }
        if ($abuseRedirect === 0) {
            $abuseMessages[] = ['type' => 'danger', 'text' => 'That code was not recognised, so nothing was changed.'];
        } else {
            $abuseDone = dynamicQrRestore($abuseRedirect, $adminUser, $abuseReason);
            if ($abuseDone['ok'] === true) {
                adminReauthTouch($adminUser);
                adminFlashAdd('success', $abuseDone['message']);
                adminRedirect('/admin/abuse/#suspended');
            }
            $abuseMessages[] = ['type' => 'danger', 'text' => $abuseDone['message']];
        }
    } elseif ($abuseAction === 'report_status') {
        $abuseReport = 0;
        if (isset($_POST['report']) && is_string($_POST['report']) && preg_match('/^[1-9][0-9]{0,18}$/D', $_POST['report']) === 1) {
            $abuseReport = (int) $_POST['report'];
        }
        $abuseStatus = '';
        if (isset($_POST['status']) && is_string($_POST['status'])) {
            $abuseStatus = $_POST['status'];
        }
        $abuseDone = abuseReportSetStatus($adminUser, $abuseReport, $abuseStatus);
        if ($abuseDone['ok'] === true) {
            adminReauthTouch($adminUser);
            adminFlashAdd('success', $abuseDone['message']);
            adminRedirect('/admin/abuse/#reports');
        }
        $abuseMessages[] = ['type' => 'danger', 'text' => $abuseDone['message']];
    } else {
        $abuseMessages[] = ['type' => 'danger', 'text' => 'That request was not understood, so nothing was changed.'];
    }
}

// ==============================================================================
// 📥 What the page shows
// ==============================================================================
$abuseReady = (adminTableExists('tblAbuseReports') && dynamicQrUpgrade0008Ready());
$abuseOpen      = [];
$abuseDealt     = [];
$abuseSuspended = [];
if ($abuseReady) {
    $abuseOpen      = abuseReportList(true, 100);
    $abuseDealt     = abuseReportList(false, 30);
    $abuseSuspended = abuseSuspendedList(200);
}
$abuseCanEdit    = adminReauthIsCurrent($adminUser);
$abuseTimezone   = adminDisplayTimezone();
$abuseTimezoneTx = adminTimezoneLabel($abuseTimezone);
$abuseCategories = abuseReportCategories();
$abuseSettings   = abuseReportSettings();
$abuseStatusText = ['new' => 'New', 'reviewing' => 'Being looked at', 'actioned' => 'Dealt with (action taken)', 'dismissed' => 'Dismissed (no action needed)'];

// ==============================================================================
// 📄 Page variables
// ==============================================================================
$pageTitle       = 'Abuse reports';
$pageDescription = 'Private screen for CueRCode Global Admins: reports about dynamic QR codes, and suspended codes.';
$pageNoIndex     = true;
$bodyClass       = 'page-admin page-admin-abuse';
$pageCSS         = [];
$pageJS          = ['/assets/js/cuercode-admin.js'];

$adminActive   = 'abuse';
$abuseMessages = array_merge(adminFlashTake(), $abuseMessages);

require_once CUERCODE_INCLUDES_DIR . 'header.php';
?>

    <section class="py-5">
        <div class="container">
            <div class="row">
                <div class="col-lg-3">
                    <?php require_once CUERCODE_INCLUDES_DIR . 'admin_nav.php'; ?>
                </div>

                <div class="col-lg-9">
                    <h1 class="h2 fw-bold mb-3">Abuse reports</h1>
                    <p class="text-body-secondary mb-4">
                        Reports about dynamic QR codes from the public report page, and every suspended code.
                        A code is suspended automatically when <?php echo (int) $abuseSettings['autoSuspendReports']; ?> different people report it
                        within <?php echo (int) $abuseSettings['windowHours']; ?> hours (only reports that passed the human check count), or when
                        the daily link-safety check finds its destination listed as harmful. Only an admin can lift a suspension.
                        Every change needs your password and a reason, and is kept in the admin change log.
                    </p>

                    <?php echo adminRenderMessages($abuseMessages); ?>

                    <?php if (!$abuseReady) { ?>
                    <div class="card shadow-sm mb-4">
                        <div class="card-body">
                            <p class="mb-2"><?php echo adminStatusHtml('warning', 'Database upgrade needed - reports cannot be shown until the waiting upgrades are applied.'); ?></p>
                            <a href="/admin/upgrades/">See database upgrades</a>
                        </div>
                    </div>
                    <?php } ?>

                    <?php if ($abuseReady && !$abuseCanEdit) { ?>
                    <div class="card shadow-sm mb-4">
                        <div class="card-body">
                            <p class="mb-3"><?php echo adminStatusHtml('locked', 'Changes are locked. Confirm your password to suspend a code, lift a suspension or change a report.'); ?></p>
                            <a class="btn btn-primary" href="/admin/reauth/?next=<?php echo rawurlencode('/admin/abuse/'); ?>">
                                <i class="fa-solid fa-unlock me-1" aria-hidden="true"></i> Unlock changes - confirm your password
                            </a>
                        </div>
                    </div>
                    <?php } ?>

                    <?php /* 📋 Open reports */ ?>
                    <div class="card shadow-sm mb-4" id="reports">
                        <div class="card-header bg-transparent">
                            <h2 class="h5 mb-0"><i class="fa-solid fa-inbox me-2" aria-hidden="true"></i>Reports still open</h2>
                        </div>
                        <div class="card-body">
                            <?php if (!$abuseReady) { ?>
                                <p class="mb-0 text-body-secondary">Not available until the database upgrades are applied.</p>
                            <?php } elseif ($abuseOpen === null) { ?>
                                <p class="mb-0"><?php echo adminStatusHtml('warning', 'The reports could not be read just now. Please reload the page.'); ?></p>
                            <?php } elseif ($abuseOpen === []) { ?>
                                <p class="mb-0 text-body-secondary">No open reports.</p>
                            <?php } else { ?>
                            <ul class="list-unstyled mb-0">
                                <?php foreach ($abuseOpen as $abuseRow) { ?>
                                <?php
                                $abuseCategoryText = (string) $abuseRow['category'];
                                if (isset($abuseCategories[$abuseRow['category']])) {
                                    $abuseCategoryText = $abuseCategories[$abuseRow['category']];
                                }
                                ?>
                                <li class="border rounded p-3 mb-3">
                                    <p class="fw-semibold mb-1 text-break">
                                        Report #<?php echo (int) $abuseRow['reportID']; ?>: <?php echo adminEscape($abuseCategoryText); ?>
                                    </p>
                                    <dl class="row small mb-2">
                                        <dt class="col-sm-4">Received</dt>
                                        <dd class="col-sm-8"><?php echo adminEscape(adminFormatUtcForDisplay((string) $abuseRow['createdAt'], $abuseTimezone)); ?> (<?php echo adminEscape($abuseTimezoneTx); ?>)</dd>
                                        <dt class="col-sm-4">Code given</dt>
                                        <dd class="col-sm-8 text-break"><code><?php echo adminEscape((string) $abuseRow['shortCode']); ?></code></dd>
                                        <dt class="col-sm-4">Matches</dt>
                                        <dd class="col-sm-8 text-break">
                                            <?php if ($abuseRow['matchedCode'] === null) { ?>
                                                No dynamic code here has that code.
                                            <?php } else { ?>
                                                Account <?php echo (int) $abuseRow['ownerUserID']; ?>; destination (not a link):
                                                <code><?php echo adminEscape((string) $abuseRow['destinationURL']); ?></code>
                                                <?php if ($abuseRow['suspendedAt'] !== null) { ?><br><span class="fw-semibold">Suspended.</span><?php } ?>
                                            <?php } ?>
                                        </dd>
                                        <dt class="col-sm-4">Human check</dt>
                                        <dd class="col-sm-8">
                                            <?php if ((int) $abuseRow['countedForSuspension'] === 1) { ?>
                                                Passed: it counts towards the automatic suspension.
                                            <?php } else { ?>
                                                Not passed (no JavaScript, or the check could not be reached): it does not count automatically.
                                            <?php } ?>
                                        </dd>
                                        <dt class="col-sm-4">What they wrote</dt>
                                        <dd class="col-sm-8 text-break cuercode-keep-lines"><?php if ($abuseRow['details'] === null || $abuseRow['details'] === '') { ?>Nothing.<?php } else { echo adminEscape((string) $abuseRow['details']); } ?></dd>
                                        <dt class="col-sm-4">Their email</dt>
                                        <dd class="col-sm-8 text-break"><?php if ($abuseRow['reporterEmail'] === '') { ?>Not given.<?php } else { echo adminEscape((string) $abuseRow['reporterEmail']); } ?></dd>
                                        <dt class="col-sm-4">Status</dt>
                                        <dd class="col-sm-8"><?php echo adminEscape($abuseStatusText[$abuseRow['status']]); ?></dd>
                                    </dl>
                                    <?php if ($abuseCanEdit) { ?>
                                    <form method="POST" action="/admin/abuse/" class="d-flex flex-wrap gap-2 align-items-end" novalidate>
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="abuse_action" value="report_status">
                                        <input type="hidden" name="report" value="<?php echo (int) $abuseRow['reportID']; ?>">
                                        <div>
                                            <label class="form-label small mb-1" for="abuse-status-<?php echo (int) $abuseRow['reportID']; ?>">Mark report #<?php echo (int) $abuseRow['reportID']; ?> as</label>
                                            <select class="form-select form-select-sm" id="abuse-status-<?php echo (int) $abuseRow['reportID']; ?>" name="status">
                                                <?php foreach ($abuseStatusText as $abuseStatusKey => $abuseStatusLabel) { ?>
                                                <option value="<?php echo adminEscape($abuseStatusKey); ?>"<?php if ($abuseStatusKey === $abuseRow['status']) { ?> selected<?php } ?>><?php echo adminEscape($abuseStatusLabel); ?></option>
                                                <?php } ?>
                                            </select>
                                        </div>
                                        <button type="submit" class="btn btn-outline-primary btn-sm">Save status</button>
                                    </form>
                                    <?php } ?>
                                </li>
                                <?php } ?>
                            </ul>
                            <?php } ?>
                        </div>
                    </div>

                    <?php /* 🚫 Suspend a code */ ?>
                    <div class="card shadow-sm mb-4" id="suspend">
                        <div class="card-header bg-transparent">
                            <h2 class="h5 mb-0"><i class="fa-solid fa-ban me-2" aria-hidden="true"></i>Suspend a code</h2>
                        </div>
                        <div class="card-body">
                            <?php if ($abuseReady && $abuseCanEdit) { ?>
                            <form method="POST" action="/admin/abuse/" novalidate>
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="abuse_action" value="suspend">
                                <div class="mb-3">
                                    <label class="form-label" for="abuse-code">The code (for example Ab3dE9x, or QRmy.Link/Ab3dE9x)</label>
                                    <input class="form-control" type="text" id="abuse-code" name="code" maxlength="300" autocomplete="off" spellcheck="false" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label" for="abuse-suspend-reason">Reason (required)</label>
                                    <input class="form-control" type="text" id="abuse-suspend-reason" name="reason" maxlength="500" required aria-describedby="abuse-suspend-reason-help">
                                    <div class="form-text" id="abuse-suspend-reason-help">One line, up to 500 characters. Kept in the admin change log; the owner and the abuse mailbox are emailed. Its open reports are marked as dealt with.</div>
                                </div>
                                <button type="submit" class="btn btn-danger">
                                    <i class="fa-solid fa-ban me-1" aria-hidden="true"></i> Suspend this code
                                </button>
                            </form>
                            <?php } else { ?>
                                <p class="mb-0 text-body-secondary">Confirm your password first (above) to suspend a code.</p>
                            <?php } ?>
                        </div>
                    </div>

                    <?php /* 🚫 Suspended codes */ ?>
                    <div class="card shadow-sm mb-4" id="suspended">
                        <div class="card-header bg-transparent">
                            <h2 class="h5 mb-0"><i class="fa-solid fa-circle-pause me-2" aria-hidden="true"></i>Suspended codes</h2>
                        </div>
                        <div class="card-body">
                            <?php if (!$abuseReady) { ?>
                                <p class="mb-0 text-body-secondary">Not available until the database upgrades are applied.</p>
                            <?php } elseif ($abuseSuspended === null) { ?>
                                <p class="mb-0"><?php echo adminStatusHtml('warning', 'The suspended codes could not be read just now. Please reload the page.'); ?></p>
                            <?php } elseif ($abuseSuspended === []) { ?>
                                <p class="mb-0 text-body-secondary">No code is suspended.</p>
                            <?php } else { ?>
                            <ul class="list-unstyled mb-0">
                                <?php foreach ($abuseSuspended as $abuseRow) { ?>
                                <li class="border rounded p-3 mb-3">
                                    <p class="fw-semibold mb-1 text-break"><code><?php echo adminEscape(dynamicQrPrintedAddress($abuseRow)); ?></code></p>
                                    <dl class="row small mb-2">
                                        <dt class="col-sm-4">Owner</dt>
                                        <dd class="col-sm-8 text-break">Account <?php echo (int) $abuseRow['ownerUserID']; ?><?php if ($abuseRow['ownerEmail'] !== null) { ?> (<?php echo adminEscape(adminMaskEmail((string) $abuseRow['ownerEmail'])); ?>)<?php } ?></dd>
                                        <dt class="col-sm-4">Destination (not a link)</dt>
                                        <dd class="col-sm-8 text-break"><code><?php echo adminEscape((string) $abuseRow['destinationURL']); ?></code></dd>
                                        <dt class="col-sm-4">Suspended</dt>
                                        <dd class="col-sm-8">
                                            <?php echo adminEscape(adminFormatUtcForDisplay((string) $abuseRow['suspendedAt'], $abuseTimezone)); ?> (<?php echo adminEscape($abuseTimezoneTx); ?>),
                                            <?php if ($abuseRow['suspendedByEmail'] === null) { ?>automatically<?php } else { ?>by <?php echo adminEscape((string) $abuseRow['suspendedByEmail']); ?><?php } ?>
                                        </dd>
                                        <dt class="col-sm-4">Why</dt>
                                        <dd class="col-sm-8 text-break"><?php echo adminEscape((string) $abuseRow['suspensionNote']); ?></dd>
                                        <dt class="col-sm-4">On Go2My.Link</dt>
                                        <dd class="col-sm-8"><?php if ($abuseRow['suspensionAppliedAt'] === null) { ?>Not confirmed yet - sent again automatically every minute.<?php } else { ?>Confirmed: the short address shows the neutral page.<?php } ?></dd>
                                    </dl>
                                    <?php if ($abuseCanEdit) { ?>
                                    <form method="POST" action="/admin/abuse/" novalidate>
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="abuse_action" value="restore">
                                        <input type="hidden" name="redirect" value="<?php echo (int) $abuseRow['redirectID']; ?>">
                                        <div class="mb-2">
                                            <label class="form-label small mb-1" for="abuse-restore-<?php echo (int) $abuseRow['redirectID']; ?>">Reason for lifting the suspension (required)</label>
                                            <input class="form-control form-control-sm" type="text" id="abuse-restore-<?php echo (int) $abuseRow['redirectID']; ?>" name="reason" maxlength="500" required>
                                        </div>
                                        <button type="submit" class="btn btn-outline-primary btn-sm">
                                            <i class="fa-solid fa-rotate-left me-1" aria-hidden="true"></i> Lift this suspension
                                        </button>
                                        <p class="form-text mb-0">The destination is checked with the link-safety service again first. The code's open reports are dismissed.</p>
                                    </form>
                                    <?php } ?>
                                </li>
                                <?php } ?>
                            </ul>
                            <?php } ?>
                        </div>
                    </div>

                    <?php /* ✅ Recently dealt with */ ?>
                    <div class="card shadow-sm mb-4" id="dealt">
                        <div class="card-header bg-transparent">
                            <h2 class="h5 mb-0"><i class="fa-solid fa-check me-2" aria-hidden="true"></i>Recently dealt with</h2>
                        </div>
                        <div class="card-body">
                            <?php if (!$abuseReady || $abuseDealt === null || $abuseDealt === []) { ?>
                                <p class="mb-0 text-body-secondary">Nothing to show.</p>
                            <?php } else { ?>
                            <ul class="list-unstyled mb-0 small">
                                <?php foreach ($abuseDealt as $abuseRow) { ?>
                                <li class="mb-2 text-break">
                                    Report #<?php echo (int) $abuseRow['reportID']; ?> about <code><?php echo adminEscape((string) $abuseRow['shortCode']); ?></code>:
                                    <?php echo adminEscape($abuseStatusText[$abuseRow['status']]); ?>,
                                    <?php echo adminEscape(adminFormatUtcForDisplay((string) $abuseRow['handledAt'], $abuseTimezone)); ?><?php if ($abuseRow['handledByEmail'] !== null) { ?> by <?php echo adminEscape((string) $abuseRow['handledByEmail']); ?><?php } ?>.
                                    <?php if ($abuseRow['personalDetailsRemovedAt'] !== null) { ?>Personal details removed.<?php } ?>
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
