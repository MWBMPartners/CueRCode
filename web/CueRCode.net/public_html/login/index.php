<?php
/**
 * ==============================================================================
 * 🚪 CueRCode - Sign In Page (Epic E3 stage 2b, #52/#14)
 * ==============================================================================
 *
 * Path: public_html/login/index.php
 *
 * A login form that mirrors register/index.php's progressive-enhancement
 * pattern: with JavaScript, cuercode-account.js POSTs JSON to
 * /api/v1/auth/login; without JavaScript, this page re-POSTs to itself and
 * calls loginUser() (fn_auth.php) directly - the SAME engine function either
 * path ultimately calls.
 *
 * GENERIC ERROR MESSAGING (no enumeration): loginUser() returns an IDENTICAL
 * message/error_code whether the account does not exist, the password is
 * wrong, the account is locked, or it is not yet verified - this page never
 * adds any additional detail on top of that message.
 *
 * 🤖 HUMAN CHECK (#163): humanCheckVerifyRequest() (fn_human_check.php) must
 * pass before loginUser() tries the password - the same call the JSON route
 * makes, rate limited per visitor address before the provider is asked. Off
 * until a Global Admin enters a provider's keys. Decision 0037: if EVERY
 * configured provider is down (by the server's own test), sign-in is let
 * through without the check and recorded in the activity log; the password,
 * the wrong-password lock-out and the limits still apply. If the check ever
 * locks out the only Global Admin it can be switched off on the server:
 * php _includes/cli/human_check.php off
 *
 * ✉️ NO JAVASCRIPT (decision 0038): the widget's <noscript> part has a
 * "Sign in by email confirmation instead" button. It goes through
 * noScriptSignInStart() (fn_no_script_route.php): strict limits, then the
 * correct password, then an emailed single-use link. This page then shows
 * "check your email" with a Continue button: after the link is approved in
 * ANOTHER browser, Continue signs THIS browser in (opening the link in THIS
 * browser signs it in directly, after a button press).
 *
 * OPEN-REDIRECT PROTECTION: an optional `?next=` query parameter controls the
 * post-login destination. Only a same-site, root-relative path (starting with
 * exactly one '/', never '//' or a scheme) is honoured - anything else falls
 * back to the default '/account/' destination. This mirrors the "open-redirect
 * parameters are allowlisted" standing security rule.
 *
 * @package     CueRCode
 * @subpackage  Pages / Account
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2 (Epic E3 stage 2b, #52/#14)
 * @see         _functions/fn_auth.php - loginUser()
 * @see         _functions/fn_human_check.php - humanCheckVerifyRequest(), humanCheckWidgetHtml()
 * @see         _functions/fn_no_script_route.php - noScriptSignInStart(), noScriptSignInContinue()
 * @see         public_html/login/confirm/index.php - the page the email links to
 * @see         public_html/assets/js/cuercode-account.js
 */

// ==============================================================================
// 🚀 Load Application Bootstrap
// ==============================================================================
require_once __DIR__ . '/../../_includes/bootstrap.php';

// ==============================================================================
// 🔗 resolveSafeNextUrl() - Allowlisted, same-site-only redirect target
// ==============================================================================

/**
 * 🔗 Resolve a safe post-login redirect target from a caller-supplied value.
 *
 * Only a root-relative path (starts with exactly one '/', never '//' - which
 * a browser would interpret as protocol-relative to an attacker's host - and
 * never containing a scheme like 'javascript:' or 'https:') is accepted.
 * Anything else falls back to the provided default.
 *
 * @param mixed  $rawNext The raw, untrusted `?next=` query value.
 * @param string $default The safe fallback destination.
 *
 * @return string A safe, same-site, root-relative path.
 */
function resolveSafeNextUrl(mixed $rawNext, string $default): string
{
    if (!is_string($rawNext) || $rawNext === '') {
        return $default;
    }

    // 🛡️ Refuse any control character (tab, CR, LF, NUL, DEL ...) and any
    // backslash ANYWHERE in the value (#153). Browsers silently strip tabs and
    // line breaks from a Location address, so '/<TAB>/evil.example' would
    // become '//evil.example' (another website). Same rules as
    // adminSafeNextPath() in _functions/fn_admin.php.
    if (preg_match('/[\x00-\x1F\x7F]/', $rawNext) === 1 || str_contains($rawNext, '\\')) {
        return $default;
    }

    // 🛡️ Must start with a single '/' and NOT with '//' or '/\' (both are
    // browser-interpretable as protocol-relative to a DIFFERENT host).
    if (!str_starts_with($rawNext, '/') || str_starts_with($rawNext, '//') || str_starts_with($rawNext, '/\\')) {
        return $default;
    }

    // 🛡️ Reject anything containing a scheme separator - defence in depth
    // against something like '/x:attacker.example.com'.
    if (str_contains($rawNext, ':')) {
        return $default;
    }

    // 📏 Cap length defensively.
    if (mb_strlen($rawNext) > 512) {
        return $default;
    }

    return $rawNext;
}

$nextUrl = resolveSafeNextUrl($_GET['next'] ?? null, '/account/');

// ==============================================================================
// 🚪 Already logged in? Send them straight to their destination.
// ==============================================================================
if (function_exists('currentUser') && currentUser() !== null) {
    header('Location: ' . $nextUrl);
    exit;
}

// ==============================================================================
// 📤 Handle Non-JS Form POST (Graceful Degradation)
// ==============================================================================

$loginError          = null;
$postedEmail         = '';
$emailSignInNotice   = '';
$emailSignInStarted  = false;

// 📌 GDPR/CCPA erasure landing flag (#37/#52) - public_html/account/index.php's
// no-JS "Delete my account" flow redirects here with `?erased=1` after a
// SUCCESSFUL erasure (logoutCurrentUser() has already run by that point, so
// this is a plain query flag, not session state - no PII, nothing to
// validate beyond "is this flag present").
$accountErasedNotice = isset($_GET['erased']) && $_GET['erased'] === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_submit'])) {
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

    $password = isset($_POST['password']) ? (string) $_POST['password'] : '';

    $humanCheck = ['ok' => false, 'reason' => ''];

    if (!$csrfValid) {
        $loginError = 'Your session has expired. Please reload the page and try again.';
    } elseif ($postedEmail === '' || $password === '') {
        $loginError = 'Email and password are both required.';
    } elseif (humanCheckEmailRouteChosen($_POST) && humanCheckIsOn()) {
        // ✉️ Decision 0038: no JavaScript - limits, then the password, then
        //    an emailed single-use link. Nobody is signed in on this request.
        $emailSignInStarted = true;
        if (isset($_SERVER['HTTP_USER_AGENT']) && is_string($_SERVER['HTTP_USER_AGENT'])) {
            $emailUserAgent = $_SERVER['HTTP_USER_AGENT'];
        } else {
            $emailUserAgent = '';
        }
        $emailSignIn = noScriptSignInStart($postedEmail, $password, getClientIP(), $emailUserAgent, $nextUrl);
        if ($emailSignIn['outcome'] === 'sent') {
            $emailSignInNotice = $emailSignIn['message'];
        } else {
            $loginError = $emailSignIn['message'];
        }
    } else {
        // 🤖 #163: the human check, before any password is tried.
        $humanCheck = humanCheckVerifyRequest($_POST, ['purpose' => 'login', 'formRateLimit' => true]);
        if (!$humanCheck['ok']) {
            $loginError = $humanCheck['message'];
        }
    }

    if ($loginError === null && !$emailSignInStarted && function_exists('loginUser')) {
        // 🌍 Decision 0014: getClientIP() (fn_client_ip.php) is loaded on
        //    every page by bootstrap.php.
        $clientIp = getClientIP();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $result = loginUser($postedEmail, $password, $clientIp, $userAgent);

        if ($result['success']) {
            // 📝 Decision 0037: a sign-in let through during an outage is noted.
            humanCheckNoteOutageSignIn($humanCheck, $result);
            header('Location: ' . $nextUrl);
            exit;
        }

        $loginError = $result['message'];
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['login_email_continue']) || isset($_POST['login_email_cancel']))) {
    // ✉️ Decision 0038: "Continue" (after the emailed link was approved in
    //    another browser) or "Start again".
    $emailCsrf = '';
    if (isset($_POST['csrf_token']) && is_string($_POST['csrf_token'])) {
        $emailCsrf = $_POST['csrf_token'];
    }

    if (!validateCSRFToken($emailCsrf)) {
        $loginError = 'Your session has expired. Please reload the page and try again.';
    } elseif (isset($_POST['login_email_cancel'])) {
        noScriptSignInForget();
    } else {
        if (isset($_SERVER['HTTP_USER_AGENT']) && is_string($_SERVER['HTTP_USER_AGENT'])) {
            $emailUserAgent = $_SERVER['HTTP_USER_AGENT'];
        } else {
            $emailUserAgent = '';
        }
        $emailContinue = noScriptSignInContinue(getClientIP(), $emailUserAgent);
        if ($emailContinue['outcome'] === 'signed_in') {
            header('Location: ' . $emailContinue['next']);
            exit;
        }
        if ($emailContinue['outcome'] === 'waiting') {
            $emailSignInNotice = 'The sign-in has not been approved yet. Open the link in the email we sent, press the button on that page, then press Continue here.';
        } else {
            $loginError = 'That email sign-in has expired or is no longer valid. Please sign in again.';
        }
    }
}

// ✉️ Decision 0038: this browser is waiting for its emailed sign-in link.
$emailSignInPending = (noScriptSignInPending() !== null);
if ($nextUrl !== '/account/') {
    $emailFormAction = '/login/?next=' . rawurlencode($nextUrl);
} else {
    $emailFormAction = '/login/';
}

// ==============================================================================
// 🔐 SSO providers (Epic E2, #55/#19/#20/#28) - rendered ONLY when a
// provider is both enabled AND fully configured (ssoProviderIsUsable()) -
// a fresh install therefore shows NO SSO buttons at all until an owner
// deliberately configures + enables at least one provider.
// ==============================================================================

$ssoLoginProviders = [];

if (function_exists('ssoSupportedProviders')) {
    foreach (ssoSupportedProviders() as $ssoProviderKey) {
        $ssoProviderMeta = ssoProviderUiMeta($ssoProviderKey);

        if ($ssoProviderMeta['usable']) {
            $ssoLoginProviders[] = $ssoProviderMeta;
        }
    }
}

// ==============================================================================
// ⚙️ Page Variables
// ==============================================================================

$pageTitle       = 'Sign In';
$pageDescription = 'Sign in to your CueRCode account to access your saved QR codes and account settings.';
$bodyClass       = 'page-account page-login';
$pageCSS         = [];
$pageJS          = ['/assets/js/cuercode-human-check.js', '/assets/js/cuercode-account.js'];

// ==============================================================================
// 📄 Render Page
// ==============================================================================

require_once CUERCODE_INCLUDES_DIR . 'header.php';
?>

    <?php /* ====================================================================
         🚪 Sign In Form
         ==================================================================== */ ?>
    <section class="py-5">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-md-7 col-lg-5">
                    <div class="card shadow-sm">
                        <div class="card-body p-4">
                            <h1 class="h3 fw-bold mb-3 text-center">
                                Sign in
                            </h1>

                            <?php if ($accountErasedNotice) { ?>
                            <div class="alert alert-success" role="status" id="account-erased-notice">
                                <i class="fa-solid fa-circle-check me-1" aria-hidden="true"></i>
                                Your account has been deleted. You have been signed out of every device.
                            </div>
                            <?php } ?>

                            <?php if ($loginError !== null) { ?>
                            <div class="alert alert-danger" role="alert" id="login-form-errors">
                                <i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i>
                                <?php echo htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                            <?php } ?>

                            <?php /* 🔔 Live region for JS-driven (AJAX) error/status messages */ ?>
                            <div id="login-ajax-alert" class="alert d-none" role="alert" aria-live="assertive"></div>

                            <?php if ($emailSignInPending) { ?>
                            <?php /* ✉️ Decision 0038: waiting for the emailed sign-in link. */ ?>
                            <div class="alert alert-info" role="status" id="login-email-pending">
                                <p class="mb-2">
                                    <i class="fa-solid fa-envelope-open-text me-1" aria-hidden="true"></i>
                                    <?php if ($emailSignInNotice !== '') { ?>
                                    <?php echo htmlspecialchars($emailSignInNotice, ENT_QUOTES, 'UTF-8'); ?>
                                    <?php } else { ?>
                                    We have emailed you a sign-in link. Open it and press the button on that page.
                                    <?php } ?>
                                </p>
                                <p class="mb-2">If you open the link on another device, press Continue here afterwards to finish signing in on this browser.</p>
                                <form method="POST" action="<?php echo htmlspecialchars($emailFormAction, ENT_QUOTES, 'UTF-8'); ?>" class="d-flex flex-wrap gap-2 mb-0">
                                    <?php echo csrfField(); ?>
                                    <button type="submit" class="btn btn-primary" name="login_email_continue" value="1">
                                        <i class="fa-solid fa-right-to-bracket me-1" aria-hidden="true"></i> Continue
                                    </button>
                                    <button type="submit" class="btn btn-outline-secondary" name="login_email_cancel" value="1">Start again</button>
                                </form>
                            </div>
                            <?php } ?>

                            <form method="POST" action="/login/<?php echo $nextUrl !== '/account/' ? '?next=' . rawurlencode($nextUrl) : ''; ?>" id="login-form" novalidate
                                  data-next-url="<?php echo htmlspecialchars($nextUrl, ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="login_submit" value="1">

                                <div class="mb-3">
                                    <label for="login-email" class="form-label">Email address</label>
                                    <input type="email" class="form-control" id="login-email" name="email"
                                           value="<?php echo htmlspecialchars($postedEmail, ENT_QUOTES, 'UTF-8'); ?>"
                                           autocomplete="email" required maxlength="254">
                                </div>

                                <div class="mb-2">
                                    <label for="login-password" class="form-label">Password</label>
                                    <input type="password" class="form-control" id="login-password" name="password"
                                           autocomplete="current-password" required maxlength="256">
                                </div>

                                <?php /* 🤖 Human check (#163) - empty while it is off; its
                                     <noscript> part offers the email route (0038) */ ?>
                                <?php echo humanCheckWidgetHtml(null, 'login'); ?>

                                <div class="mb-4 text-end">
                                    <a href="/forgot-password/" class="small">Forgot password?</a>
                                    <?php /* 📨 #128: the sign-in message is the same for an unverified account
                                         (no enumeration), so the way to a new link is always offered. */ ?>
                                    <br>
                                    <a href="/resend-verification/" class="small">Didn't get your verification email? Send a new link</a>
                                </div>

                                <div class="d-grid">
                                    <button type="submit" class="btn btn-primary btn-lg" id="login-submit-btn">
                                        <i class="fa-solid fa-right-to-bracket me-1" aria-hidden="true"></i> Sign in
                                    </button>
                                </div>
                            </form>

                            <?php if (!empty($ssoLoginProviders)) { ?>
                            <?php /* ================================================
                                 🔐 SSO sign-in buttons (Epic E2, #55/#19/#20/#28)
                                 ================================================ */ ?>
                            <div class="d-flex align-items-center my-3" role="separator" aria-label="or">
                                <hr class="flex-grow-1">
                                <span class="px-2 text-body-secondary small">or</span>
                                <hr class="flex-grow-1">
                            </div>
                            <div class="d-grid gap-2" id="sso-login-buttons">
                                <?php foreach ($ssoLoginProviders as $ssoProvider) { ?>
                                <a class="btn btn-outline-secondary"
                                   href="/auth/<?php echo rawurlencode($ssoProvider['provider']); ?><?php echo ($nextUrl !== '/account/') ? '?next=' . rawurlencode($nextUrl) : ''; ?>">
                                    <i class="<?php echo htmlspecialchars($ssoProvider['iconClass'], ENT_QUOTES, 'UTF-8'); ?> me-2" aria-hidden="true"></i>
                                    Sign in with <?php echo htmlspecialchars($ssoProvider['displayName'], ENT_QUOTES, 'UTF-8'); ?>
                                </a>
                                <?php } ?>
                            </div>
                            <?php } ?>

                            <p class="text-center mt-3 mb-0">
                                Don't have an account? <a href="/register/">Create one</a>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

<?php
require_once CUERCODE_INCLUDES_DIR . 'footer.php';
