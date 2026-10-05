<?php
/**
 * ==============================================================================
 * ✅ CueRCode - Email Verification Page (Epic E3 stage 2b, #52/#12)
 * ==============================================================================
 *
 * Path: public_html/verify-email/index.php
 *
 * Reads the `?token=` query parameter from the verification email link and
 * lets the visitor CONFIRM (an explicit button click, not an automatic
 * on-load action) that they want to verify their email address.
 *
 * ⚠️ WHY A CONFIRMATION CLICK, NOT AN AUTOMATIC GET-TRIGGERED VERIFY: email
 * verification tokens are SINGLE-USE (fn_auth.php verifyEmailToken()). Many
 * corporate mail gateways, antivirus link scanners, and "Safe Links"-style
 * proxies automatically GET-fetch every URL inside an email BEFORE the human
 * recipient ever opens it, to scan for malware/phishing. If this page
 * verified the token immediately on GET, such a scanner would silently burn
 * the single-use token and the real user would see a confusing "already
 * used" error despite never having clicked anything themselves. Requiring an
 * explicit POST (a real button click, or its AJAX equivalent) avoids this
 * entirely - scanners fetch the page's HTML but do not submit forms or run
 * arbitrary JavaScript click handlers.
 *
 * @package     CueRCode
 * @subpackage  Pages / Account
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2 (Epic E3 stage 2b, #52/#12)
 * @see         _functions/fn_auth.php - verifyEmailToken()
 * @see         public_html/assets/js/cuercode-account.js
 */

// ==============================================================================
// 🚀 Load Application Bootstrap
// ==============================================================================
require_once __DIR__ . '/../../_includes/bootstrap.php';

// ==============================================================================
// 🎫 Resolve the token (GET, for display/hidden-field only - never auto-consumed)
// ==============================================================================

if (isset($_GET['token']) && is_string($_GET['token'])) {
    $rawToken = trim($_GET['token']);
} else {
    $rawToken = '';
}

// 📏 Defensive length cap (matches fn_api_accounts.php's own token field cap).
if (mb_strlen($rawToken) > 128) {
    $rawToken = '';
}

$verifyResult = null;

// ==============================================================================
// 📤 Handle the confirmation POST (the only path that actually consumes the token)
// ==============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_submit'])) {
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

    if (!$csrfValid) {
        $verifyResult = [
            'success' => false,
            'message' => 'Your session has expired. Please reload the page and try again.',
        ];
    } elseif ($postedToken === '' || !function_exists('verifyEmailToken')) {
        $verifyResult = [
            'success' => false,
            'message' => 'This verification link is invalid or has expired.',
        ];
    } else {
        $verifyResult = verifyEmailToken($postedToken);
    }
}

// ==============================================================================
// ⚙️ Page Variables
// ==============================================================================

$pageTitle       = 'Verify Your Email';
$pageDescription = 'Confirm your email address to activate your CueRCode account.';
$pageNoIndex     = true; // 🚫 Keep out of search results (#137)
$bodyClass       = 'page-account page-verify-email';
$pageCSS         = [];
$pageJS          = ['/assets/js/cuercode-account.js'];

// ==============================================================================
// 📄 Render Page
// ==============================================================================

require_once CUERCODE_INCLUDES_DIR . 'header.php';
?>

    <?php /* ====================================================================
         ✅ Email Verification
         ==================================================================== */ ?>
    <section class="py-5">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-md-8 col-lg-6">
                    <div class="card shadow-sm">
                        <div class="card-body p-4 text-center">
                            <h1 class="h3 fw-bold mb-3">
                                Verify your email
                            </h1>

                            <?php if ($verifyResult !== null) { ?>
                                <?php if ($verifyResult['success']) { ?>
                                <div class="alert alert-success" role="status">
                                    <i class="fa-solid fa-circle-check me-1" aria-hidden="true"></i>
                                    <?php echo htmlspecialchars($verifyResult['message'], ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                                <a href="/login/" class="btn btn-primary">
                                    <i class="fa-solid fa-right-to-bracket me-1" aria-hidden="true"></i> Sign in
                                </a>
                                <?php } else { ?>
                                <div class="alert alert-danger" role="alert" id="verify-result-error">
                                    <i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i>
                                    <?php echo htmlspecialchars($verifyResult['message'], ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                                <p>
                                    Need a new link? <a href="/register/">Register again</a> or
                                    <a href="/forgot-password/">reset your password</a> if you already
                                    have an account.
                                </p>
                                <?php } ?>
                            <?php } elseif ($rawToken === '') { ?>
                                <div class="alert alert-danger" role="alert">
                                    <i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i>
                                    This verification link is missing its token. Please use the exact
                                    link from your verification email.
                                </div>
                            <?php } else { ?>
                                <p class="text-body-secondary mb-4">
                                    Click the button below to confirm your email address and activate
                                    your account.
                                </p>

                                <?php /* 🔔 Live region for JS-driven (AJAX) status messages */ ?>
                                <div id="verify-ajax-alert" class="alert d-none" role="alert" aria-live="assertive"></div>

                                <form method="POST" action="/verify-email/" id="verify-form">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="verify_submit" value="1">
                                    <input type="hidden" name="token" id="verify-token"
                                           value="<?php echo htmlspecialchars($rawToken, ENT_QUOTES, 'UTF-8'); ?>">

                                    <button type="submit" class="btn btn-primary btn-lg" id="verify-submit-btn">
                                        <i class="fa-solid fa-envelope-circle-check me-1" aria-hidden="true"></i>
                                        Verify my email
                                    </button>
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
