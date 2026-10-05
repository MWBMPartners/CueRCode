<?php
/**
 * ==============================================================================
 * 📝 CueRCode - Account Registration Page (Epic E3 stage 2b, #52/#12)
 * ==============================================================================
 *
 * Path: public_html/register/index.php
 *
 * A registration form (email, password + confirm, live password-strength
 * hint) that mirrors public_html/index.php's own progressive-enhancement
 * convention: with JavaScript, cuercode-account.js intercepts the submit and
 * POSTs JSON to /api/v1/auth/register (with the X-CSRF-Token header); WITHOUT
 * JavaScript, this exact page re-POSTs to itself and calls registerUser()
 * (fn_auth.php) directly, server-side, using the SAME engine function the
 * JSON API handler calls into - there is only ever one code path that
 * actually creates an account.
 *
 * ANTI-ENUMERATION: registerUser() ALWAYS returns an identical generic
 * success shape regardless of whether the email was new or already
 * registered (see fn_auth.php) - this page renders that SAME generic message
 * either way, so a visitor can never learn whether a given email already has
 * an account from this page's response.
 *
 * @package     CueRCode
 * @subpackage  Pages / Account
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2 (Epic E3 stage 2b, #52/#12)
 * @see         _functions/fn_auth.php         - registerUser()
 * @see         _functions/fn_password.php     - validatePasswordPolicy()
 * @see         _functions/fn_validation.php   - validateEmail()
 * @see         public_html/assets/js/cuercode-account.js
 */

// ==============================================================================
// 🚀 Load Application Bootstrap
// ==============================================================================
require_once __DIR__ . '/../../_includes/bootstrap.php';

// ==============================================================================
// 🚪 Already logged in? Send them to their account instead.
// ==============================================================================
if (function_exists('currentUser') && currentUser() !== null) {
    header('Location: /account/');
    exit;
}

// ==============================================================================
// 📤 Handle Non-JS Form POST (Graceful Degradation)
// ==============================================================================

$registerResult  = null;
$formErrors      = [];
$postedEmail     = '';
$postedDisplay   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_submit'])) {
    // 🛡️ Validate CSRF token (classic form POST - $_POST, not the JSON API's
    // X-CSRF-Token header).
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
    if (isset($_POST['display_name'])) {
        $postedDisplay = trim((string) $_POST['display_name']);
    }

    $password        = isset($_POST['password']) ? (string) $_POST['password'] : '';
    $passwordConfirm  = isset($_POST['password_confirm']) ? (string) $_POST['password_confirm'] : '';

    if (!$csrfValid) {
        $formErrors[] = 'Your session has expired. Please reload the page and try again.';
    } elseif ($postedEmail === '' || $password === '') {
        $formErrors[] = 'Email and password are both required.';
    } elseif (!function_exists('validateEmail') || !validateEmail($postedEmail)) {
        $formErrors[] = 'Please enter a valid email address.';
    } elseif ($password !== $passwordConfirm) {
        $formErrors[] = 'Passwords do not match.';
    } else {
        $minLength = 8;
        if (function_exists('getConfig')) {
            $minLength = (int) getConfig('password_min_length', 8);
        }

        $policy = function_exists('validatePasswordPolicy') ? validatePasswordPolicy($password, $minLength) : ['valid' => true, 'error' => null];

        if (!$policy['valid']) {
            $formErrors[] = (string) $policy['error'];
        }
    }

    if (empty($formErrors) && function_exists('registerUser')) {
        $displayNameArg = $postedDisplay !== '' ? $postedDisplay : null;
        $registerResult = registerUser($postedEmail, $password, $displayNameArg);
    }
}

// ==============================================================================
// 🔐 SSO providers (Epic E2, #55/#19/#20/#28) - rendered ONLY when a
// provider is both enabled AND fully configured (ssoProviderIsUsable()) -
// signing up via SSO auto-verifies the email through the IdP, so these
// buttons double as an instant-registration path (see fn_oauth.php's
// decideOAuthAccountLinkAction() 'create_new' branch).
// ==============================================================================

$ssoRegisterProviders = [];

if (function_exists('ssoSupportedProviders')) {
    foreach (ssoSupportedProviders() as $ssoProviderKey) {
        $ssoProviderMeta = ssoProviderUiMeta($ssoProviderKey);

        if ($ssoProviderMeta['usable']) {
            $ssoRegisterProviders[] = $ssoProviderMeta;
        }
    }
}

// ==============================================================================
// ⚙️ Page Variables
// ==============================================================================

$pageTitle       = 'Create an Account';
$pageDescription = 'Create a free CueRCode account to save your QR codes and come back to download them any time.';
$bodyClass       = 'page-account page-register';
$pageCSS         = [];
$pageJS          = ['/assets/js/cuercode-account.js'];

// ==============================================================================
// 📄 Render Page
// ==============================================================================

require_once CUERCODE_INCLUDES_DIR . 'header.php';
?>

    <?php /* ====================================================================
         📝 Registration Form
         ==================================================================== */ ?>
    <section class="py-5">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-md-7 col-lg-5">
                    <div class="card shadow-sm">
                        <div class="card-body p-4">
                            <h1 class="h3 fw-bold mb-3 text-center">
                                Create your account
                            </h1>
                            <p class="text-body-secondary text-center mb-4">
                                Save your QR codes and come back to download them any time.
                            </p>

                            <?php if ($registerResult !== null && $registerResult['success']) { ?>
                                <?php /* ✅ Generic anti-enumeration success message */ ?>
                                <div class="alert alert-success" role="status">
                                    <i class="fa-solid fa-envelope-circle-check me-1" aria-hidden="true"></i>
                                    <?php echo htmlspecialchars($registerResult['message'], ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                                <p class="text-center mb-0">
                                    <a href="/login/">Return to sign in</a>
                                </p>
                            <?php } else { ?>

                            <?php if (!empty($formErrors)) { ?>
                            <div class="alert alert-danger" role="alert" id="register-form-errors">
                                <i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i>
                                <ul class="mb-0 ps-3">
                                    <?php foreach ($formErrors as $formError) { ?>
                                    <li><?php echo htmlspecialchars($formError, ENT_QUOTES, 'UTF-8'); ?></li>
                                    <?php } ?>
                                </ul>
                            </div>
                            <?php } ?>

                            <?php /* 🔔 Live region for JS-driven (AJAX) error/status messages */ ?>
                            <div id="register-ajax-alert" class="alert d-none" role="alert" aria-live="assertive"></div>

                            <?php if (!empty($ssoRegisterProviders)) { ?>
                            <?php /* ================================================
                                 🔐 SSO sign-up buttons (Epic E2, #55/#19/#20/#28)
                                 ================================================ */ ?>
                            <div class="d-grid gap-2 mb-3" id="sso-register-buttons">
                                <?php foreach ($ssoRegisterProviders as $ssoProvider) { ?>
                                <a class="btn btn-outline-secondary"
                                   href="/auth/<?php echo rawurlencode($ssoProvider['provider']); ?>">
                                    <i class="<?php echo htmlspecialchars($ssoProvider['iconClass'], ENT_QUOTES, 'UTF-8'); ?> me-2" aria-hidden="true"></i>
                                    Sign up with <?php echo htmlspecialchars($ssoProvider['displayName'], ENT_QUOTES, 'UTF-8'); ?>
                                </a>
                                <?php } ?>
                            </div>
                            <div class="d-flex align-items-center my-3" role="separator" aria-label="or">
                                <hr class="flex-grow-1">
                                <span class="px-2 text-body-secondary small">or use your email</span>
                                <hr class="flex-grow-1">
                            </div>
                            <?php } ?>

                            <form method="POST" action="/register/" id="register-form" novalidate>
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="register_submit" value="1">

                                <div class="mb-3">
                                    <label for="reg-email" class="form-label">Email address</label>
                                    <input type="email" class="form-control" id="reg-email" name="email"
                                           value="<?php echo htmlspecialchars($postedEmail, ENT_QUOTES, 'UTF-8'); ?>"
                                           autocomplete="email" required maxlength="254"
                                           aria-describedby="reg-email-help">
                                    <div id="reg-email-help" class="form-text">We'll send a verification link to this address.</div>
                                </div>

                                <div class="mb-3">
                                    <label for="reg-display-name" class="form-label">Display name <span class="text-body-secondary">(optional)</span></label>
                                    <input type="text" class="form-control" id="reg-display-name" name="display_name"
                                           value="<?php echo htmlspecialchars($postedDisplay, ENT_QUOTES, 'UTF-8'); ?>"
                                           autocomplete="name" maxlength="100">
                                </div>

                                <div class="mb-3">
                                    <label for="reg-password" class="form-label">Password</label>
                                    <input type="password" class="form-control" id="reg-password" name="password"
                                           autocomplete="new-password" required minlength="8" maxlength="256"
                                           aria-describedby="reg-password-help reg-password-strength">
                                    <div id="reg-password-help" class="form-text">At least 8 characters. Avoid common or repeated passwords.</div>
                                    <?php /* 📊 Live password-strength hint (JS-enhanced only; harmless empty <div> without JS) */ ?>
                                    <div id="reg-password-strength" class="form-text" aria-live="polite"></div>
                                </div>

                                <div class="mb-4">
                                    <label for="reg-password-confirm" class="form-label">Confirm password</label>
                                    <input type="password" class="form-control" id="reg-password-confirm" name="password_confirm"
                                           autocomplete="new-password" required minlength="8" maxlength="256">
                                </div>

                                <div class="d-grid">
                                    <button type="submit" class="btn btn-primary btn-lg" id="register-submit-btn">
                                        <i class="fa-solid fa-user-plus me-1" aria-hidden="true"></i> Create account
                                    </button>
                                </div>
                            </form>

                            <p class="text-center mt-3 mb-0">
                                Already have an account? <a href="/login/">Sign in</a>
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
