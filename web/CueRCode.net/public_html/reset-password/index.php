<?php
/**
 * ==============================================================================
 * 🔁 CueRCode - Reset Password Page (Epic E3 stage 2b, #52/#14)
 * ==============================================================================
 *
 * Path: public_html/reset-password/index.php
 *
 * Reads the `?token=` query parameter from the password-reset email link and
 * a new password + confirmation, then calls resetPasswordWithToken()
 * (fn_auth.php) directly for the no-JS path (the JSON API's
 * /auth/reset-password calls the identical engine function for the
 * JS-enhanced path).
 *
 * Unlike verify-email/index.php, this page does NOT need the "confirmation
 * click before consuming the token" precaution - the reset token is only
 * consumed together with a NEW PASSWORD the visitor must actively type, so a
 * mail-scanner's passive GET-prefetch of the link (with no submitted body)
 * can never trigger resetPasswordWithToken() at all.
 *
 * @package     CueRCode
 * @subpackage  Pages / Account
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2 (Epic E3 stage 2b, #52/#14)
 * @see         _functions/fn_auth.php - resetPasswordWithToken()
 * @see         public_html/assets/js/cuercode-account.js
 */

// ==============================================================================
// 🚀 Load Application Bootstrap
// ==============================================================================
require_once __DIR__ . '/../../_includes/bootstrap.php';

// ==============================================================================
// 🎫 Resolve the token (GET, echoed into a hidden field for the POST below)
// ==============================================================================

if (isset($_GET['token']) && is_string($_GET['token'])) {
    $rawToken = trim($_GET['token']);
} else {
    $rawToken = '';
}

if (mb_strlen($rawToken) > 128) {
    $rawToken = '';
}

$resetResult = null;

// ==============================================================================
// 📤 Handle Non-JS Form POST (Graceful Degradation)
// ==============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_submit'])) {
    if (isset($_POST['csrf_token'])) {
        $csrfTokenValue = $_POST['csrf_token'];
    } else {
        $csrfTokenValue = '';
    }

    if (function_exists('validateCSRFToken')) {
        $csrfValid = validateCSRFToken($csrfTokenValue);
    } else {
        $csrfValid = true;
    }

    if (isset($_POST['token'])) {
        $postedToken = trim((string) $_POST['token']);
    } else {
        $postedToken = '';
    }

    $newPassword    = isset($_POST['new_password']) ? (string) $_POST['new_password'] : '';
    $confirmPassword = isset($_POST['new_password_confirm']) ? (string) $_POST['new_password_confirm'] : '';

    if (!$csrfValid) {
        $resetResult = ['success' => false, 'message' => 'Your session has expired. Please reload the page and try again.'];
    } elseif ($postedToken === '') {
        $resetResult = ['success' => false, 'message' => 'This password reset link is invalid or has expired.'];
    } elseif ($newPassword === '' || $newPassword !== $confirmPassword) {
        $resetResult = ['success' => false, 'message' => 'Passwords do not match.'];
    } elseif (function_exists('resetPasswordWithToken')) {
        $resetResult = resetPasswordWithToken($postedToken, $newPassword);
    }
}

// ==============================================================================
// ⚙️ Page Variables
// ==============================================================================

$pageTitle       = 'Reset Password';
$pageDescription = 'Set a new password for your CueRCode account.';
$pageNoIndex     = true; // 🚫 Keep out of search results (#137)
$bodyClass       = 'page-account page-reset-password';
$pageCSS         = [];
$pageJS          = ['/assets/js/cuercode-account.js'];

// ==============================================================================
// 📄 Render Page
// ==============================================================================

require_once CUERCODE_INCLUDES_DIR . 'header.php';
?>

    <?php /* ====================================================================
         🔁 Reset Password Form
         ==================================================================== */ ?>
    <section class="py-5">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-md-7 col-lg-5">
                    <div class="card shadow-sm">
                        <div class="card-body p-4">
                            <h1 class="h3 fw-bold mb-3 text-center">
                                Reset your password
                            </h1>

                            <?php if ($resetResult !== null && $resetResult['success']) { ?>
                                <div class="alert alert-success" role="status">
                                    <i class="fa-solid fa-circle-check me-1" aria-hidden="true"></i>
                                    <?php echo htmlspecialchars($resetResult['message'], ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                                <a href="/login/" class="btn btn-primary">
                                    <i class="fa-solid fa-right-to-bracket me-1" aria-hidden="true"></i> Sign in
                                </a>
                            <?php } elseif ($rawToken === '' && $resetResult === null) { ?>
                                <div class="alert alert-danger" role="alert">
                                    <i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i>
                                    This password reset link is missing its token. Please use the exact
                                    link from your password reset email.
                                </div>
                                <p class="text-center mb-0">
                                    <a href="/forgot-password/">Request a new reset link</a>
                                </p>
                            <?php } else { ?>

                            <?php if ($resetResult !== null && !$resetResult['success']) { ?>
                            <div class="alert alert-danger" role="alert" id="reset-form-errors">
                                <i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i>
                                <?php echo htmlspecialchars($resetResult['message'], ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                            <?php } ?>

                            <?php /* 🔔 Live region for JS-driven (AJAX) error/status messages */ ?>
                            <div id="reset-ajax-alert" class="alert d-none" role="alert" aria-live="assertive"></div>

                            <form method="POST" action="/reset-password/" id="reset-form" novalidate>
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="reset_submit" value="1">
                                <input type="hidden" name="token" id="reset-token"
                                       value="<?php echo htmlspecialchars($rawToken, ENT_QUOTES, 'UTF-8'); ?>">

                                <div class="mb-3">
                                    <label for="reset-new-password" class="form-label">New password</label>
                                    <input type="password" class="form-control" id="reset-new-password" name="new_password"
                                           autocomplete="new-password" required minlength="8" maxlength="256"
                                           aria-describedby="reset-password-strength">
                                    <div id="reset-password-strength" class="form-text" aria-live="polite"></div>
                                </div>

                                <div class="mb-4">
                                    <label for="reset-new-password-confirm" class="form-label">Confirm new password</label>
                                    <input type="password" class="form-control" id="reset-new-password-confirm" name="new_password_confirm"
                                           autocomplete="new-password" required minlength="8" maxlength="256">
                                </div>

                                <div class="d-grid">
                                    <button type="submit" class="btn btn-primary btn-lg" id="reset-submit-btn">
                                        <i class="fa-solid fa-rotate me-1" aria-hidden="true"></i>
                                        Reset password
                                    </button>
                                </div>
                            </form>
                            <?php } ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

<?php
require_once CUERCODE_INCLUDES_DIR . 'footer.php';
