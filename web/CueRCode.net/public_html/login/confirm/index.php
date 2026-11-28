<?php
/**
 * ==============================================================================
 * ✉️ CueRCode - Finish signing in by email (no JavaScript, #163 / decision 0038)
 * ==============================================================================
 *
 * Path: public_html/login/confirm/index.php
 *
 * The page the email sign-in link opens (fn_no_script_route.php,
 * noScriptSignInStart() sends it only after the correct password was typed).
 *
 * OPENING THE LINK CHANGES NOTHING: email security scanners open links on
 * their own, so a plain visit only shows a button. Pressing it (a POST with
 * the page's security token) is what counts, once:
 *   - in the SAME browser that asked to sign in: that browser is signed in;
 *   - in ANOTHER browser (for example a phone): the page says clearly that
 *     THIS browser will not be signed in, and pressing approves the sign-in
 *     on the first browser only, where the person then presses Continue.
 * A used, expired or unknown link, or one for an account whose password was
 * reset (or that signed out everywhere, or was locked or suspended) since,
 * says so and does nothing.
 *
 * Works without JavaScript (a normal form). Kept out of search results.
 *
 * @package     CueRCode
 * @subpackage  Pages / Account
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       2026-11-27 (#163, decision 0038)
 * @see         _functions/fn_no_script_route.php - noScriptSignInPreview(), noScriptSignInApprove()
 */

// ==============================================================================
// 🚀 Load Application Bootstrap
// ==============================================================================
require_once __DIR__ . '/../../../_includes/bootstrap.php';

// ==============================================================================
// 🎫 The link's token (from the address, or the form's hidden field)
// ==============================================================================

if (isset($_GET['token']) && is_string($_GET['token'])) {
    $confirmToken = trim($_GET['token']);
} else {
    $confirmToken = '';
}
if (mb_strlen($confirmToken) > 128) {
    $confirmToken = '';
}

$confirmOutcome = '';
$confirmError   = '';

// ==============================================================================
// 📤 The button press (POST only - opening the link never changes anything)
// ==============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_submit'])) {
    $confirmCsrf = '';
    if (isset($_POST['csrf_token']) && is_string($_POST['csrf_token'])) {
        $confirmCsrf = $_POST['csrf_token'];
    }

    $postedToken = '';
    if (isset($_POST['token']) && is_string($_POST['token'])) {
        $postedToken = trim($_POST['token']);
    }
    $confirmToken = accountLinkTokenForForm($confirmToken, $postedToken);

    if (!validateCSRFToken($confirmCsrf)) {
        $confirmError = 'Your session has expired. Please open the link from the email again.';
    } else {
        if (isset($_SERVER['HTTP_USER_AGENT']) && is_string($_SERVER['HTTP_USER_AGENT'])) {
            $confirmUserAgent = $_SERVER['HTTP_USER_AGENT'];
        } else {
            $confirmUserAgent = '';
        }

        $confirmResult = noScriptSignInApprove($confirmToken, getClientIP(), $confirmUserAgent);
        if ($confirmResult['outcome'] === 'signed_in') {
            header('Location: ' . $confirmResult['next']);
            exit;
        }
        $confirmOutcome = $confirmResult['outcome'];
    }
}

// 🔍 What to show (reading only).
$confirmPreview = ['state' => 'invalid', 'bindingMatches' => false];
if ($confirmOutcome === '') {
    $confirmPreview = noScriptSignInPreview($confirmToken);
}

// ==============================================================================
// ⚙️ Page Variables
// ==============================================================================

$pageTitle       = 'Finish Signing In';
$pageDescription = 'Finish signing in to your CueRCode account by email.';
$pageNoIndex     = true; // 🚫 Keep out of search results (#137)
$bodyClass       = 'page-account page-login-confirm';
$pageCSS         = [];
$pageJS          = [];

// ==============================================================================
// 📄 Render Page
// ==============================================================================

require_once CUERCODE_INCLUDES_DIR . 'header.php';
?>

    <?php /* ====================================================================
         ✉️ Finish signing in
         ==================================================================== */ ?>
    <section class="py-5">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-md-7 col-lg-5">
                    <div class="card shadow-sm">
                        <div class="card-body p-4">
                            <h1 class="h3 fw-bold mb-3 text-center">
                                Finish signing in
                            </h1>

                            <?php if ($confirmError !== '') { ?>
                            <div class="alert alert-danger" role="alert">
                                <i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i>
                                <?php echo htmlspecialchars($confirmError, ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                            <?php } ?>

                            <?php if ($confirmOutcome === 'approved') { ?>
                            <div class="alert alert-success" role="status" id="login-confirm-approved">
                                <i class="fa-solid fa-circle-check me-1" aria-hidden="true"></i>
                                Approved. Go back to the browser where you typed your password and press
                                <strong>Continue</strong> there to finish signing in.
                                <strong>This browser has not been signed in.</strong>
                            </div>
                            <?php } elseif ($confirmOutcome === 'invalid' || ($confirmError === '' && $confirmPreview['state'] !== 'usable')) { ?>
                            <div class="alert alert-warning" role="alert" id="login-confirm-invalid">
                                <i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i>
                                This sign-in link has expired, has already been used, or is no longer valid.
                                Please sign in again.
                            </div>
                            <p class="text-center mb-0"><a href="/login/">Go to sign in</a></p>
                            <?php } elseif ($confirmError === '') { ?>
                            <?php if ($confirmPreview['bindingMatches']) { ?>
                            <p>Press the button to finish signing in on this browser.</p>
                            <?php } else { ?>
                            <div class="alert alert-info" role="note" id="login-confirm-other-browser">
                                <p class="mb-2">
                                    <strong>This is not the browser where the password was typed.</strong>
                                    Pressing the button approves the sign-in on that other browser only.
                                    <strong>This browser will not be signed in.</strong>
                                </p>
                                <p class="mb-0">Only press it if you asked to sign in a few minutes ago. If you did not, close this page and change your password with "Forgot password".</p>
                            </div>
                            <?php } ?>
                            <form method="POST" action="/login/confirm/" novalidate>
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="confirm_submit" value="1">
                                <input type="hidden" name="token" value="<?php echo htmlspecialchars($confirmToken, ENT_QUOTES, 'UTF-8'); ?>">
                                <div class="d-grid">
                                    <button type="submit" class="btn btn-primary btn-lg" id="login-confirm-btn">
                                        <i class="fa-solid fa-right-to-bracket me-1" aria-hidden="true"></i>
                                        <?php if ($confirmPreview['bindingMatches']) { ?>
                                        Sign in on this browser
                                        <?php } else { ?>
                                        Approve the sign-in on the other browser
                                        <?php } ?>
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
