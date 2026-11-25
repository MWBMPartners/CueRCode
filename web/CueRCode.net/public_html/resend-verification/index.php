<?php
/**
 * ==============================================================================
 * 📨 CueRCode - Send Me a New Verification Link (#128)
 * ==============================================================================
 *
 * Path: public_html/resend-verification/index.php
 *
 * For a person who signed up but never got (or lost, or let expire) the email
 * with their verification link. They type their email address and, if that
 * address has an account still waiting for verification, a fresh link is
 * queued. Earlier links stop working.
 *
 * 🙈 NO ACCOUNT ENUMERATION: the answer is ALWAYS the same plain message,
 *    whether or not the address belongs to a waiting account, and whether or
 *    not the request was held back by a rate limit. Only a missing/expired
 *    security token or a badly formed address gets a different message, and
 *    both of those say nothing about any account.
 * ⏱️ The limits (gap between sends, daily cap, per-IP cap) live in
 *    requestNewVerificationLink() (fn_auth.php) and are read from settings.
 * 🧩 Works with JavaScript off: a normal form POST to this same page.
 * 🤖 There is no bot-protection widget here because none of the sibling forms
 *    (sign in, register, forgot password) use one yet; the per-IP and
 *    per-account limits are the protection. Add it here when it is added to them.
 *
 * @package     CueRCode
 * @subpackage  Pages / Account
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2 (#128)
 * @see         _functions/fn_auth.php - requestNewVerificationLink()
 */

// ==============================================================================
// 🚀 Load Application Bootstrap
// ==============================================================================
require_once __DIR__ . '/../../_includes/bootstrap.php';

// ==============================================================================
// 📤 Handle the form POST
// ==============================================================================

$requestedLink = false;
$formError     = null;
$postedEmail   = '';
$resultMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend_submit'])) {
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

    if (isset($_POST['email']) && is_string($_POST['email'])) {
        $postedEmail = trim($_POST['email']);
    }

    if (!$csrfValid) {
        $formError = 'Your session has expired. Please reload the page and try again.';
    } elseif ($postedEmail === '' || mb_strlen($postedEmail) > 254 || !function_exists('validateEmail') || !validateEmail($postedEmail)) {
        $formError = 'Please enter a valid email address.';
    } elseif (function_exists('requestNewVerificationLink')) {
        // 🌍 Decision 0014: getClientIP() (fn_client_ip.php) is loaded on
        //    every page by bootstrap.php, so this per-address limit counts the
        //    real visitor, not a forwarder and not a faked header.
        $clientIp = getClientIP();

        $resendResult = requestNewVerificationLink($postedEmail, $clientIp);

        if ($resendResult['success']) {
            // 📌 The identical message either way - see the file header.
            $requestedLink = true;
            $resultMessage = $resendResult['message'];
        } else {
            $formError = $resendResult['message'];
        }
    }
}

// ==============================================================================
// ⚙️ Page Variables
// ==============================================================================

$pageTitle       = 'Send a New Verification Link';
$pageDescription = 'Ask for a new email verification link for your CueRCode account.';
$pageNoIndex     = true; // 🚫 Keep out of search results (#137)
$bodyClass       = 'page-account page-resend-verification';
$pageCSS         = [];
$pageJS          = [];

// ==============================================================================
// 📄 Render Page
// ==============================================================================

require_once CUERCODE_INCLUDES_DIR . 'header.php';
?>

    <?php /* ====================================================================
         📨 Send Me a New Verification Link
         ==================================================================== */ ?>
    <section class="py-5">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-md-7 col-lg-5">
                    <div class="card shadow-sm">
                        <div class="card-body p-4">
                            <h1 class="h3 fw-bold mb-3 text-center">
                                Send me a new verification link
                            </h1>

                            <?php if ($requestedLink) { ?>
                                <?php /* ✅ Same message every time - never says whether the address has an account. */ ?>
                                <div class="alert alert-success" role="status">
                                    <i class="fa-solid fa-circle-check me-1" aria-hidden="true"></i>
                                    <?php echo htmlspecialchars($resultMessage, ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                                <p class="text-body-secondary">
                                    The new link works for <?php echo (int) CUERCODE_EMAIL_VERIFICATION_TTL_HOURS; ?> hours. Older links no longer work, so use the
                                    newest email. If nothing arrives within a few minutes you can ask again.
                                </p>
                                <p class="text-center mb-0">
                                    <a href="/login/">Return to sign in</a>
                                </p>
                            <?php } else { ?>

                            <p class="text-body-secondary text-center mb-4">
                                Enter the email address you signed up with and we'll send you a fresh
                                link to verify it.
                            </p>

                            <?php if ($formError !== null) { ?>
                            <div class="alert alert-danger" role="alert" id="resend-form-errors">
                                <i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i>
                                <?php echo htmlspecialchars($formError, ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                            <?php } ?>

                            <form method="POST" action="/resend-verification/" id="resend-form" novalidate>
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="resend_submit" value="1">

                                <div class="mb-4">
                                    <label for="resend-email" class="form-label">Email address</label>
                                    <input type="email" class="form-control" id="resend-email" name="email"
                                           value="<?php echo htmlspecialchars($postedEmail, ENT_QUOTES, 'UTF-8'); ?>"
                                           autocomplete="email" required maxlength="254">
                                </div>

                                <div class="d-grid">
                                    <button type="submit" class="btn btn-primary btn-lg" id="resend-submit-btn">
                                        <i class="fa-solid fa-paper-plane me-1" aria-hidden="true"></i>
                                        Send me a new link
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
