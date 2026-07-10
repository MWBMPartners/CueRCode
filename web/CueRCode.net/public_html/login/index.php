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

$loginError  = null;
$postedEmail = '';

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

    if (!$csrfValid) {
        $loginError = 'Your session has expired. Please reload the page and try again.';
    } elseif ($postedEmail === '' || $password === '') {
        $loginError = 'Email and password are both required.';
    } elseif (function_exists('loginUser')) {
        if (function_exists('getClientIP')) {
            $clientIp = getClientIP();
        } else {
            $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        }
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $result = loginUser($postedEmail, $password, $clientIp, $userAgent);

        if ($result['success']) {
            header('Location: ' . $nextUrl);
            exit;
        }

        $loginError = $result['message'];
    }
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
$pageJS          = ['/assets/js/cuercode-account.js'];

// ==============================================================================
// 📄 Render Page
// ==============================================================================

require_once CUERCODE_INCLUDES_DIR . 'header.php';
?>

    <!-- ====================================================================
         🚪 Sign In Form
         ==================================================================== -->
    <section class="py-5">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-md-7 col-lg-5">
                    <div class="card shadow-sm">
                        <div class="card-body p-4">
                            <h1 class="h3 fw-bold mb-3 text-center">
                                <i class="fa-solid fa-right-to-bracket me-2" aria-hidden="true"></i>
                                Sign in
                            </h1>

                            <?php if ($loginError !== null) { ?>
                            <div class="alert alert-danger" role="alert" id="login-form-errors">
                                <i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i>
                                <?php echo htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                            <?php } ?>

                            <!-- 🔔 Live region for JS-driven (AJAX) error/status messages -->
                            <div id="login-ajax-alert" class="alert d-none" role="alert" aria-live="assertive"></div>

                            <noscript>
                                <div class="alert alert-info" role="note">
                                    <i class="fa-solid fa-circle-info me-1" aria-hidden="true"></i>
                                    JavaScript is disabled - this form will submit normally and reload the page.
                                </div>
                            </noscript>

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

                                <div class="mb-4 text-end">
                                    <a href="/forgot-password/" class="small">Forgot password?</a>
                                </div>

                                <div class="d-grid">
                                    <button type="submit" class="btn btn-primary btn-lg" id="login-submit-btn">
                                        <i class="fa-solid fa-right-to-bracket me-1" aria-hidden="true"></i> Sign in
                                    </button>
                                </div>
                            </form>

                            <?php if (!empty($ssoLoginProviders)) { ?>
                            <!-- ================================================
                                 🔐 SSO sign-in buttons (Epic E2, #55/#19/#20/#28)
                                 ================================================ -->
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
