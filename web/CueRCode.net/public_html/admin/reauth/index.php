<?php
/**
 * ==============================================================================
 * 🔑 CueRCode - Admin: Confirm your password (unlock editing)
 * ==============================================================================
 *
 * Path: public_html/admin/reauth/index.php
 *
 * Looking at admin pages needs only Global Admin status; making ANY change
 * also needs the password to have been typed again recently (task 7n Phase
 * 1B, plan §6.4). This page is where that happens:
 *
 *   - the right password → the PHP session ID is renewed
 *     (session_regenerate_id(true)), editing is unlocked for this user AND
 *     this database session, the audit log gets "reauth.success", and the
 *     browser goes back to the admin page it came from (only ever an /admin/
 *     path - adminSafeNextPath());
 *   - a wrong password → "reauth.failed" is recorded (never the password),
 *     it counts toward the ordinary account lock-out like a wrong sign-in,
 *     and after 5 wrong passwords in one session the admin is signed out
 *     ("reauth.lockout");
 *   - "Lock editing now" forgets the confirmation straight away.
 *
 * This page and /admin/upgrades/ are the only admin pages that may act
 * before the admin change log table exists (a database still waiting for
 * its upgrades): their audit rows then go to the activity log instead.
 *
 * The password box is never filled back in, and the typed password is never
 * written to the page, a log, the session or the audit trail.
 *
 * @package     CueRCode
 * @subpackage  Admin
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-24 (task 7n Phase 1B, #31)
 * @see         _functions/fn_admin.php - adminVerifyReauthPassword(), adminReauthStore(), adminSafeNextPath()
 * @see         .claude/plans/7n-admin-commerce-plan.md §6.4
 */

// ==============================================================================
// 🚀 Load the application, then the admin functions, then the gate - FIRST
// ==============================================================================
require_once __DIR__ . '/../../../_includes/bootstrap.php';
require_once CUERCODE_FUNCTIONS_DIR . 'fn_admin.php';

$adminUser = requireGlobalAdminPage('/admin/reauth/');

// ==============================================================================
// 🔗 Where to go afterwards (only ever an /admin/ path)
// ==============================================================================
$reauthNextRaw = null;
if (isset($_POST['next'])) {
    $reauthNextRaw = $_POST['next'];
} elseif (isset($_GET['next'])) {
    $reauthNextRaw = $_GET['next'];
}
$reauthNext = adminSafeNextPath($reauthNextRaw, '/admin/');

// 🔁 Never send the admin back to this same page after unlocking.
if (str_starts_with($reauthNext, '/admin/reauth/')) {
    $reauthNext = '/admin/';
}

$reauthMessages = [];
$reauthFieldError = '';

// ==============================================================================
// 📤 POST: confirm the password, or lock editing
// ==============================================================================
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // 🛡️ CSRF + same-site check. No fresh password needed: this IS the
    //    password form (and "lock" only takes permission away).
    $reauthCheck = adminCheckPost($adminUser, false);

    if (!$reauthCheck['ok']) {
        $reauthMessages[] = ['type' => 'danger', 'text' => $reauthCheck['message']];
    } elseif (isset($_POST['lock_editing'])) {
        adminReauthClear();
        adminFlashAdd('success', 'Editing is locked. Confirm your password again before making changes.');
        adminRedirect('/admin/');
    } elseif (isset($_POST['reauth_submit'])) {
        $reauthPassword = '';
        if (isset($_POST['current_password']) && is_string($_POST['current_password'])) {
            $reauthPassword = $_POST['current_password'];
        }
        // 🧹 The raw POST copy is not needed any more.
        unset($_POST['current_password']);

        if ($reauthPassword === '') {
            $reauthFieldError = 'Type your password.';
            $reauthMessages[] = ['type' => 'danger', 'text' => 'There is a problem: type your password.'];
        } elseif (!$adminUser['hasPassword']) {
            $reauthMessages[] = [
                'type' => 'danger',
                'text' => 'This account has no password, so editing cannot be unlocked. Set a password with "Forgot password" on the sign-in page first.',
            ];
        } else {
            $reauthVerified = adminVerifyReauthPassword((int) $adminUser['userID'], $reauthPassword);
            $reauthPassword = '';

            if ($reauthVerified['ok']) {
                // ✅ Renew the PHP session ID (session-fixation defence), then
                //    remember the confirmation for this user + database session.
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_regenerate_id(true);
                }
                adminReauthStore($adminUser);
                adminReauthSetFailureCount($adminUser, 0);
                adminAuditOrFallback(adminAuditEntryForAdmin($adminUser, 'reauth.success', 'user', (string) $adminUser['userID'], 'success'));

                $reauthLimits = adminReauthConfiguredLimits();
                adminFlashAdd(
                    'success',
                    'Editing is unlocked. It locks again after ' . $reauthLimits['idle'] . ' minutes without a change, and after '
                    . $reauthLimits['max'] . ' minutes at most.'
                );
                adminRedirect($reauthNext);
            } elseif ($reauthVerified['unavailable']) {
                $reauthMessages[] = ['type' => 'danger', 'text' => 'Your password could not be checked just now. Please try again in a minute.'];
            } else {
                $reauthFailures = adminReauthFailureCount($adminUser) + 1;
                adminReauthSetFailureCount($adminUser, $reauthFailures);
                adminAuditOrFallback(adminAuditEntryForAdmin($adminUser, 'reauth.failed', 'user', (string) $adminUser['userID'], 'refused'));

                if (adminReauthFailureOutcome($reauthFailures) === 'lockout') {
                    // 🚪 Too many wrong passwords in one session: sign out.
                    adminAuditOrFallback(adminAuditEntryForAdmin($adminUser, 'reauth.lockout', 'user', (string) $adminUser['userID'], 'refused'));
                    adminReauthClear();
                    adminReauthSetFailureCount($adminUser, 0);
                    if (function_exists('logoutCurrentUser')) {
                        logoutCurrentUser();
                    }
                    adminRedirect('/login/?next=' . rawurlencode('/admin/'));
                }

                $reauthTriesLeft = CUERCODE_ADMIN_REAUTH_MAX_FAILURES - $reauthFailures;
                if ($reauthVerified['locked']) {
                    $reauthFieldError = 'This account is locked for a while after too many wrong passwords.';
                    $reauthMessages[] = [
                        'type' => 'danger',
                        'text' => 'This account is locked for a while after too many wrong passwords. Try again later. Tries left before you are signed out: ' . $reauthTriesLeft . '.',
                    ];
                } else {
                    $reauthFieldError = 'That password is not right.';
                    $reauthMessages[] = [
                        'type' => 'danger',
                        'text' => 'That password is not right. Tries left before you are signed out: ' . $reauthTriesLeft . '.',
                    ];
                }
            }
        }
    }
}

// ==============================================================================
// 🔓 Current state, for the page
// ==============================================================================
$reauthTimezone      = adminDisplayTimezone();
$reauthTimezoneLabel = adminTimezoneLabel($reauthTimezone);
$reauthSecondsLeft   = adminReauthSecondsLeftForAdmin($adminUser);
$reauthLimitsShown   = adminReauthConfiguredLimits();

// ==============================================================================
// 📄 Page variables
// ==============================================================================
$pageTitle       = 'Confirm your password';
$pageDescription = 'Confirm your password to unlock editing in the CueRCode admin area.';
$pageNoIndex     = true;
$bodyClass       = 'page-admin page-admin-reauth';
$pageCSS         = [];
$pageJS          = ['/assets/js/cuercode-admin.js'];

$adminActive = 'reauth';
$reauthMessages = array_merge(adminFlashTake(), $reauthMessages);

require_once CUERCODE_INCLUDES_DIR . 'header.php';
?>

    <section class="py-5">
        <div class="container">
            <div class="row">
                <div class="col-lg-3">
                    <?php require_once CUERCODE_INCLUDES_DIR . 'admin_nav.php'; ?>
                </div>

                <div class="col-lg-9">
                    <h1 class="h2 fw-bold mb-3">Confirm your password</h1>

                    <?php echo adminRenderMessages($reauthMessages); ?>

                    <?php if ($reauthSecondsLeft > 0) { ?>
                    <div class="card shadow-sm mb-4">
                        <div class="card-body">
                            <p class="mb-3">
                                <?php echo adminStatusHtml('good', 'Editing is unlocked until ' . adminFormatClockTime(time() + $reauthSecondsLeft, $reauthTimezone) . ' (' . $reauthTimezoneLabel . ').'); ?>
                            </p>
                            <form method="POST" action="/admin/reauth/">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="lock_editing" value="1">
                                <button type="submit" class="btn btn-outline-secondary">
                                    <i class="fa-solid fa-lock me-1" aria-hidden="true"></i> Lock editing now
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php } else { ?>
                    <p class="text-body-secondary mb-4" id="reauth-help">
                        To make changes in the admin area, type your password again. Editing then stays unlocked until you go
                        <?php echo (int) $reauthLimitsShown['idle']; ?> minutes without making a change, and for
                        <?php echo (int) $reauthLimitsShown['max']; ?> minutes at most. After
                        <?php echo (int) CUERCODE_ADMIN_REAUTH_MAX_FAILURES; ?> wrong passwords you are signed out.
                    </p>

                    <div class="card shadow-sm mb-4">
                        <div class="card-body">
                            <form method="POST" action="/admin/reauth/" novalidate>
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="reauth_submit" value="1">
                                <input type="hidden" name="next" value="<?php echo adminEscape($reauthNext); ?>">

                                <div class="mb-3">
                                    <label for="reauth-password" class="form-label">Your password (required)</label>
                                    <?php if ($reauthFieldError !== '') { ?>
                                    <input type="password" class="form-control is-invalid" id="reauth-password" name="current_password"
                                           autocomplete="current-password" required maxlength="256"
                                           aria-invalid="true" aria-describedby="reauth-password-error reauth-help">
                                    <div class="invalid-feedback" id="reauth-password-error"><?php echo adminEscape($reauthFieldError); ?></div>
                                    <?php } else { ?>
                                    <input type="password" class="form-control" id="reauth-password" name="current_password"
                                           autocomplete="current-password" required maxlength="256"
                                           aria-describedby="reauth-help">
                                    <?php } ?>
                                </div>

                                <button type="submit" class="btn btn-primary">
                                    <i class="fa-solid fa-unlock me-1" aria-hidden="true"></i> Confirm password and unlock editing
                                </button>
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
