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
 * 🔑 DECISION 0013 - THE PASSWORD BOX: a stranger can sign up first with
 * someone else's email address. If the real owner of the address then
 * clicked the link, the account would become active with the STRANGER's
 * password. So the link only activates an account that has a password when
 * the person also types that password. Someone who did not choose it (or has
 * forgotten it) is pointed to "Forgot password", which proves the address,
 * sets a new password, activates the account and removes every other way in.
 * Accounts with no password (Microsoft/Google only) keep the one-click
 * button. Showing the page only READS the link (to decide whether the box is
 * needed); nothing is used up until the button is pressed.
 *
 * @package     CueRCode
 * @subpackage  Pages / Account
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2 (Epic E3 stage 2b, #52/#12)
 * @see         _functions/fn_auth.php - verifyEmailToken(), emailVerificationTokenNeedsPassword()
 * @see         docs/decisions/0013-signup-first-trick-verification-needs-password-reset-removes-all.md
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

    // 🔑 Decision 0013: the password chosen at sign-up (exactly as typed,
    //    like the sign-in page). Never echoed back into the page.
    $postedPassword = null;
    if (isset($_POST['password']) && is_string($_POST['password'])) {
        $postedPassword = $_POST['password'];
    }

    // 🔁 Keep the link in the form, so a wrong password can simply be retyped.
    if ($rawToken === '' && $postedToken !== '' && mb_strlen($postedToken) <= 128) {
        $rawToken = $postedToken;
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
        $verifyResult = verifyEmailToken($postedToken, $postedPassword);
    }
}

// ==============================================================================
// 🔎 Does this link need the account's password? (READ-ONLY - decision 0013)
// ==============================================================================
// True in every case except a valid link for an account with no password.
// Nothing is used up by this check, so a mail scanner opening the page is
// still harmless.

$needsPassword = true;
if ($rawToken !== '' && function_exists('emailVerificationTokenNeedsPassword')) {
    $needsPassword = emailVerificationTokenNeedsPassword($rawToken);
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
                                <div class="alert alert-danger text-start" role="alert" id="verify-result-error">
                                    <i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i>
                                    <?php echo htmlspecialchars($verifyResult['message'], ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                                <p>
                                    Did not choose this account's password, or forgotten it?
                                    <a href="/forgot-password/">Forgot password</a> sends a link to this email
                                    address that sets a new password and activates the account.
                                    Link too old? <a href="/resend-verification/">Send me a new verification link</a>.
                                </p>
                                <?php } ?>
                            <?php } ?>

                            <?php if ($verifyResult !== null && $verifyResult['success']) { ?>
                                <?php /* ✅ Done - nothing more to show. */ ?>
                            <?php } elseif ($rawToken === '') { ?>
                                <div class="alert alert-danger" role="alert">
                                    <i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i>
                                    This verification link is missing its token. Please use the exact
                                    link from your verification email.
                                </div>
                                <p>
                                    Lost the email? <a href="/resend-verification/">Send me a new verification link</a>.
                                </p>
                            <?php } else { ?>
                                <?php if ($needsPassword) { ?>
                                <p class="text-body-secondary mb-4">
                                    To confirm your email address and activate your account, type the
                                    password you chose when you signed up.
                                </p>
                                <?php } else { ?>
                                <p class="text-body-secondary mb-4">
                                    Click the button below to confirm your email address and activate
                                    your account.
                                </p>
                                <?php } ?>

                                <?php /* 🔔 Live region for JS-driven (AJAX) status messages */ ?>
                                <div id="verify-ajax-alert" class="alert d-none text-start" role="alert" aria-live="assertive"></div>

                                <form method="POST" action="/verify-email/" id="verify-form">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="verify_submit" value="1">
                                    <input type="hidden" name="token" id="verify-token"
                                           value="<?php echo htmlspecialchars($rawToken, ENT_QUOTES, 'UTF-8'); ?>">

                                    <?php if ($needsPassword) { ?>
                                    <div class="mb-3 text-start">
                                        <label for="verify-password" class="form-label">Your password</label>
                                        <input type="password" class="form-control" id="verify-password" name="password"
                                               autocomplete="current-password" required maxlength="256"
                                               aria-describedby="verify-password-help">
                                        <div id="verify-password-help" class="form-text">
                                            The one you chose when you signed up. Did not choose it, or forgotten it?
                                            Use <a href="/forgot-password/">Forgot password</a> instead: it sends a link to
                                            this email address that sets a new password and activates the account.
                                        </div>
                                    </div>
                                    <?php } ?>

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
