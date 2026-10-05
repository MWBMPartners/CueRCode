<?php
/**
 * ==============================================================================
 * 🔑 CueRCode - Forgot Password Page (Epic E3 stage 2b, #52/#14)
 * ==============================================================================
 *
 * Path: public_html/forgot-password/index.php
 *
 * Requests a password-reset email. ALWAYS shows the identical generic "if
 * that account exists..." message regardless of whether the submitted email
 * is registered - mirrors requestPasswordReset()'s (fn_auth.php) own
 * anti-enumeration contract exactly; this page adds NO additional detail on
 * top of it.
 *
 * @package     CueRCode
 * @subpackage  Pages / Account
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2 (Epic E3 stage 2b, #52/#14)
 * @see         _functions/fn_auth.php - requestPasswordReset()
 * @see         public_html/assets/js/cuercode-account.js
 */

// ==============================================================================
// 🚀 Load Application Bootstrap
// ==============================================================================
require_once __DIR__ . '/../../_includes/bootstrap.php';

// ==============================================================================
// 📤 Handle Non-JS Form POST (Graceful Degradation)
// ==============================================================================

$requestedReset = false;
$formError      = null;
$postedEmail    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['forgot_submit'])) {
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

    if (isset($_POST['email'])) {
        $postedEmail = trim((string) $_POST['email']);
    }

    if (!$csrfValid) {
        $formError = 'Your session has expired. Please reload the page and try again.';
    } elseif ($postedEmail === '' || !function_exists('validateEmail') || !validateEmail($postedEmail)) {
        $formError = 'Please enter a valid email address.';
    } elseif (function_exists('requestPasswordReset')) {
        if (function_exists('getClientIP')) {
            $clientIp = getClientIP();
        } else {
            $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        }

        // 📌 The identical generic response either way - see file header.
        requestPasswordReset($postedEmail, $clientIp);
        $requestedReset = true;
    }
}

// ==============================================================================
// ⚙️ Page Variables
// ==============================================================================

$pageTitle       = 'Forgot Password';
$pageDescription = 'Request a password reset link for your CueRCode account.';
$bodyClass       = 'page-account page-forgot-password';
$pageCSS         = [];
$pageJS          = ['/assets/js/cuercode-account.js'];

// ==============================================================================
// 📄 Render Page
// ==============================================================================

require_once CUERCODE_INCLUDES_DIR . 'header.php';
?>

    <?php /* ====================================================================
         🔑 Forgot Password Form
         ==================================================================== */ ?>
    <section class="py-5">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-md-7 col-lg-5">
                    <div class="card shadow-sm">
                        <div class="card-body p-4">
                            <h1 class="h3 fw-bold mb-3 text-center">
                                Forgot your password?
                            </h1>

                            <?php if ($requestedReset) { ?>
                                <?php /* ✅ Generic anti-enumeration message - always shown, regardless
                                     of whether the email is actually registered. */ ?>
                                <div class="alert alert-success" role="status">
                                    <i class="fa-solid fa-circle-check me-1" aria-hidden="true"></i>
                                    If that account exists, a password reset link has been sent to it.
                                    Please check your inbox (and spam folder).
                                </div>
                                <p class="text-center mb-0">
                                    <a href="/login/">Return to sign in</a>
                                </p>
                            <?php } else { ?>

                            <p class="text-body-secondary text-center mb-4">
                                Enter your account's email address and we'll send you a link to reset
                                your password.
                            </p>

                            <?php if ($formError !== null) { ?>
                            <div class="alert alert-danger" role="alert" id="forgot-form-errors">
                                <i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i>
                                <?php echo htmlspecialchars($formError, ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                            <?php } ?>

                            <?php /* 🔔 Live region for JS-driven (AJAX) status messages */ ?>
                            <div id="forgot-ajax-alert" class="alert d-none" role="alert" aria-live="assertive"></div>

                            <form method="POST" action="/forgot-password/" id="forgot-form" novalidate>
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="forgot_submit" value="1">

                                <div class="mb-4">
                                    <label for="forgot-email" class="form-label">Email address</label>
                                    <input type="email" class="form-control" id="forgot-email" name="email"
                                           value="<?php echo htmlspecialchars($postedEmail, ENT_QUOTES, 'UTF-8'); ?>"
                                           autocomplete="email" required maxlength="254">
                                </div>

                                <div class="d-grid">
                                    <button type="submit" class="btn btn-primary btn-lg" id="forgot-submit-btn">
                                        <i class="fa-solid fa-paper-plane me-1" aria-hidden="true"></i>
                                        Send reset link
                                    </button>
                                </div>
                            </form>

                            <p class="text-center mt-3 mb-0">
                                <a href="/login/">Back to sign in</a>
                            </p>
                            <?php } ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

<?php
require_once CUERCODE_INCLUDES_DIR . 'footer.php';
