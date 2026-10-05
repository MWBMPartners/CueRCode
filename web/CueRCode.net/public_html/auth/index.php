<?php
/**
 * ==============================================================================
 * 🚦 CueRCode - SSO Sign-In Initiation Route (Epic E2, #55/#19/#20/#28)
 * ==============================================================================
 *
 * Path: public_html/auth/index.php
 *
 * Thin BROWSER-REDIRECT dispatcher for `GET/POST /auth/{provider}` (rewritten
 * from the clean URL by public_html/.htaccess into
 * `auth/index.php?provider={provider}`). Calls beginAuth() (fn_oauth.php)
 * and 302-redirects the browser to the provider's authorize URL. This is
 * NOT a JSON API endpoint - see openapi.json's dedicated (non-/api/v1)
 * documentation of this path for the full note.
 *
 * TWO DISTINCT FLOWS, both routed through the exact same beginAuth() call
 * (which itself captures whichever applies at the moment it runs):
 *
 *   1. Anonymous visitor ("Sign in with ...") - a plain GET (an ordinary
 *      `<a href>` from login/register, exactly like every other "Sign in
 *      with Google" button on the web) proceeds straight to beginAuth().
 *      There is no existing account to protect yet, and OAuth's own `state`
 *      parameter (verified in the callback) is the actual CSRF/replay
 *      defence for this flow - see fn_oauth.php's docblock.
 *
 *   2. Authenticated visitor ("Link an additional provider" from account
 *      settings) - THIS is a real, account-affecting action, so it requires
 *      a CSRF-token-validated POST (the account page's "Link" buttons are
 *      themselves POST forms with csrfField() - see public_html/account/
 *      index.php). A direct GET while authenticated (e.g. a bookmarked/
 *      retyped URL) is NOT silently honoured - it renders a one-click
 *      confirmation page containing that same POST form instead, so a
 *      link is NEVER initiated by a bare GET with no CSRF token attached.
 *
 * @package     CueRCode
 * @subpackage  Pages / Auth
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2 (Epic E2, #55/#19/#20/#28)
 * @see         _functions/fn_oauth.php - beginAuth(), ssoSupportedProviders()
 * @see         public_html/auth/callback/index.php
 */

// ==============================================================================
// 🚀 Load Application Bootstrap
// ==============================================================================
require_once __DIR__ . '/../../_includes/bootstrap.php';

// ==============================================================================
// 📋 Resolve + validate the provider from the clean-URL rewrite
// ==============================================================================

if (isset($_GET['provider'])) {
    $requestedProvider = (string) $_GET['provider'];
} else {
    $requestedProvider = '';
}

$requestedProvider = strtolower(trim($requestedProvider));

$providerIsValid = function_exists('ssoSupportedProviders')
    && in_array($requestedProvider, ssoSupportedProviders(), true);

// ==============================================================================
// 🔗 Resolve the (validated) optional post-login destination
// ==============================================================================

if (isset($_GET['next'])) {
    $rawNextUrl = $_GET['next'];
} elseif (isset($_POST['next'])) {
    $rawNextUrl = $_POST['next'];
} else {
    $rawNextUrl = null;
}

// ==============================================================================
// 👤 Is the visitor already authenticated? (determines which flow applies)
// ==============================================================================

$currentUserData = function_exists('currentUser') ? currentUser() : null;

// ==============================================================================
// 🧭 Dispatch
// ==============================================================================

$errorMessage = null;

if (!$providerIsValid) {
    $errorMessage = 'Unknown sign-in provider.';
} elseif ($currentUserData !== null) {
    // ------------------------------------------------------------------
    // 🔗 Authenticated "link an additional provider" flow - CSRF-gated.
    // ------------------------------------------------------------------
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['csrf_token'])) {
            $csrfTokenValue = (string) $_POST['csrf_token'];
        } else {
            $csrfTokenValue = '';
        }

        if (!function_exists('validateCSRFToken') || !validateCSRFToken($csrfTokenValue)) {
            $errorMessage = 'Your session has expired. Please return to your account settings and try again.';
        } else {
            $beginResult = beginAuth($requestedProvider, $rawNextUrl);

            if (!$beginResult['success']) {
                $errorMessage = $beginResult['message'];
            } else {
                header('Location: ' . $beginResult['data']['authorizeUrl']);
                exit;
            }
        }
    }
    // 📌 A bare GET while authenticated falls through to render the
    // one-click confirmation page below (never auto-redirects without a
    // validated CSRF token first).
} else {
    // ------------------------------------------------------------------
    // 🚪 Anonymous "Sign in with ..." flow - plain GET is standard here.
    // ------------------------------------------------------------------
    $beginResult = beginAuth($requestedProvider, $rawNextUrl);

    if (!$beginResult['success']) {
        $errorMessage = $beginResult['message'];
    } else {
        header('Location: ' . $beginResult['data']['authorizeUrl']);
        exit;
    }
}

// ==============================================================================
// ⚙️ Page Variables (only reached for an error, or the link-confirmation page)
// ==============================================================================

if ($providerIsValid) {
    $providerDisplayName = ssoProviderDisplayName($requestedProvider);
} else {
    $providerDisplayName = 'this provider';
}

$pageTitle       = ($errorMessage !== null) ? 'Sign-In Error' : 'Link ' . $providerDisplayName;
$pageDescription = 'CueRCode single sign-on.';
$pageNoIndex     = true; // 🚫 Keep out of search results (#137)
$bodyClass       = 'page-account page-auth';
$pageCSS         = [];
$pageJS          = [];

require_once CUERCODE_INCLUDES_DIR . 'header.php';
?>

    <!-- ====================================================================
         🔐 SSO Initiation - Error / Link-Confirmation
         ==================================================================== -->
    <section class="py-5">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-md-8 col-lg-6">
                    <div class="card shadow-sm">
                        <div class="card-body p-4 text-center">
                            <?php if ($errorMessage !== null) { ?>
                            <h1 class="h4 fw-bold mb-3">
                                <i class="fa-solid fa-triangle-exclamation me-2 text-warning" aria-hidden="true"></i>
                                Sign-in unavailable
                            </h1>
                            <div class="alert alert-danger" role="alert">
                                <?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                            <a href="/login/" class="btn btn-primary">
                                <i class="fa-solid fa-right-to-bracket me-1" aria-hidden="true"></i> Back to sign in
                            </a>
                            <?php } else { ?>
                            <h1 class="h4 fw-bold mb-3">
                                <i class="<?php echo htmlspecialchars(ssoProviderIconClass($requestedProvider), ENT_QUOTES, 'UTF-8'); ?> me-2" aria-hidden="true"></i>
                                Link <?php echo htmlspecialchars($providerDisplayName, ENT_QUOTES, 'UTF-8'); ?>?
                            </h1>
                            <p class="text-body-secondary mb-4">
                                You will be sent to <?php echo htmlspecialchars($providerDisplayName, ENT_QUOTES, 'UTF-8'); ?> to confirm.
                                No password or personal data is shared with CueRCode beyond your verified email address.
                            </p>
                            <form method="POST" action="/auth/<?php echo rawurlencode($requestedProvider); ?>">
                                <?php echo csrfField(); ?>
                                <?php if ($rawNextUrl !== null) { ?>
                                <input type="hidden" name="next" value="<?php echo htmlspecialchars((string) $rawNextUrl, ENT_QUOTES, 'UTF-8'); ?>">
                                <?php } ?>
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="<?php echo htmlspecialchars(ssoProviderIconClass($requestedProvider), ENT_QUOTES, 'UTF-8'); ?> me-1" aria-hidden="true"></i>
                                    Continue to <?php echo htmlspecialchars($providerDisplayName, ENT_QUOTES, 'UTF-8'); ?>
                                </button>
                            </form>
                            <p class="text-center mt-3 mb-0">
                                <a href="/account/">Cancel and return to my account</a>
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
