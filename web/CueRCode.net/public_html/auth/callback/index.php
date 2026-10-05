<?php
/**
 * ==============================================================================
 * ✅ CueRCode - SSO Callback Route (Epic E2, #55/#19/#20/#28)
 * ==============================================================================
 *
 * Path: public_html/auth/callback/index.php
 *
 * Thin BROWSER-REDIRECT dispatcher for `GET /auth/callback/{provider}`
 * (rewritten from the clean URL by public_html/.htaccess into
 * `auth/callback/index.php?provider={provider}`) - the EXACT redirect_uri
 * every supported IdP is registered with. This is NOT a JSON API endpoint -
 * see openapi.json's dedicated (non-/api/v1) documentation of this path.
 *
 * Flow:
 *   1. If the IdP itself reports an error (`?error=...` - e.g. the visitor
 *      cancelled consent), show an accessible error page. No `code`/`state`
 *      exist in this case, so completeAuth() is never called.
 *   2. completeAuth() (fn_oauth.php) verifies `state`, exchanges the code
 *      (+ PKCE verifier) for tokens, and cryptographically verifies the
 *      id_token (JWKS/RS256, iss/aud/exp/iat/nonce) - returning the VERIFIED
 *      subject/email/email_verified. Any failure here shows a generic,
 *      accessible error page (the specific reason is logged server-side,
 *      never echoed to the response body beyond a safe generic message).
 *   3. applyOAuthAccountLink() (fn_oauth.php) applies the ACCOUNT-LINKING
 *      decision table (decideOAuthAccountLinkAction()'s docblock has the
 *      full rationale) - creating a new pre-verified account, logging an
 *      existing linked user in, linking an additional provider onto the
 *      CURRENTLY authenticated account, or refusing (unverified email /
 *      email-collision-with-an-unlinked-existing-account / identity-linked-
 *      elsewhere).
 *   4. On a genuine AUTHENTICATION outcome (`create_new`/`login_existing`),
 *      issueUserSession() (fn_auth.php) is called - the SAME hardened
 *      session path every other login goes through, including PHP session
 *      ID regeneration (session fixation mitigation). A pure "link an
 *      additional provider" outcome does NOT touch the caller's existing
 *      session - they were already authenticated before this flow began.
 *   5. Redirect to the caller's (re-validated) safe post-login destination.
 *
 * @package     CueRCode
 * @subpackage  Pages / Auth
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2 (Epic E2, #55/#19/#20/#28)
 * @see         _functions/fn_oauth.php - completeAuth(), applyOAuthAccountLink(), decideOAuthAccountLinkAction()
 * @see         _functions/fn_auth.php  - issueUserSession()
 * @see         public_html/auth/index.php
 */

// ==============================================================================
// 🚀 Load Application Bootstrap
// ==============================================================================
require_once __DIR__ . '/../../../_includes/bootstrap.php';

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
// ⚠️ Did the IdP itself report an error (e.g. the visitor cancelled consent)?
// ==============================================================================

if (isset($_GET['error'])) {
    $idpErrorCode = (string) $_GET['error'];
} else {
    $idpErrorCode = '';
}

// ==============================================================================
// 🧭 Dispatch
// ==============================================================================

$errorMessage  = null;
$successResult = null;

if (!$providerIsValid) {
    $errorMessage = 'Unknown sign-in provider.';

    // 🧹 Best-effort cleanup: burn any pending session state even for an
    // unrecognised provider, so a stray/forged callback cannot leave a
    // dangling entry behind.
    if (isset($_GET['state']) && function_exists('oauthConsumePendingState')) {
        oauthConsumePendingState($requestedProvider, (string) $_GET['state']);
    }
} elseif ($idpErrorCode !== '') {
    // 🚫 The IdP itself declined (access_denied, consent_required, ...) -
    // never call completeAuth() with no code/state to process. Clean up any
    // pending session state so a subsequent attempt starts fresh.
    if (isset($_GET['state'])) {
        oauthConsumePendingState($requestedProvider, (string) $_GET['state']);
    }

    $errorMessage = 'Sign-in with ' . ssoProviderDisplayName($requestedProvider) . ' was cancelled or could not be completed.';
} else {
    if (isset($_GET['code'])) {
        $authorizationCode = (string) $_GET['code'];
    } else {
        $authorizationCode = '';
    }

    if (isset($_GET['state'])) {
        $stateValue = (string) $_GET['state'];
    } else {
        $stateValue = '';
    }

    if ($authorizationCode === '' || $stateValue === '') {
        $errorMessage = 'This sign-in link is incomplete or invalid. Please try again.';
    } else {
        $completeResult = completeAuth($requestedProvider, $authorizationCode, $stateValue);

        if (!$completeResult['success']) {
            $errorMessage = $completeResult['message'];
        } else {
            $verifiedIdentity = $completeResult['data'];

            $linkResult = applyOAuthAccountLink(
                $verifiedIdentity['provider'],
                $verifiedIdentity['subject'],
                $verifiedIdentity['email'],
                $verifiedIdentity['emailVerified'],
                $verifiedIdentity['displayName'],
                $verifiedIdentity['tokensEncrypted'],
                $verifiedIdentity['linkUserId']
            );

            if (!$linkResult['success']) {
                $errorMessage = $linkResult['message'];
            } else {
                $resolvedUserId = $linkResult['data']['userID'];
                $resolvedAction = $linkResult['data']['action'];

                // 🎫 Only a GENUINE authentication outcome issues a fresh,
                // hardened session (PHP session ID regenerated - session
                // fixation mitigation). A pure "link an additional provider"
                // outcome leaves the caller's ALREADY-authenticated session
                // exactly as it was.
                if ($resolvedAction === 'create_new' || $resolvedAction === 'login_existing') {
                    if (function_exists('getClientIP')) {
                        $clientIp = getClientIP();
                    } elseif (isset($_SERVER['REMOTE_ADDR'])) {
                        $clientIp = $_SERVER['REMOTE_ADDR'];
                    } else {
                        $clientIp = 'unknown';
                    }

                    if (isset($_SERVER['HTTP_USER_AGENT'])) {
                        $userAgent = $_SERVER['HTTP_USER_AGENT'];
                    } else {
                        $userAgent = '';
                    }

                    issueUserSession((int) $resolvedUserId, $clientIp, $userAgent);
                }

                $successResult = [
                    'nextUrl' => resolveSafeSameSiteRedirect($verifiedIdentity['nextUrl'], '/account/'),
                    'action'  => $resolvedAction,
                ];
            }
        }
    }
}

// ==============================================================================
// 🚀 Success - redirect to the caller's (re-validated) safe destination
// ==============================================================================

if ($successResult !== null) {
    header('Location: ' . $successResult['nextUrl']);
    exit;
}

// ==============================================================================
// ⚙️ Page Variables (only reached on error)
// ==============================================================================

$pageTitle       = 'Sign-In Error';
$pageDescription = 'CueRCode single sign-on.';
$pageNoIndex     = true; // 🚫 Keep out of search results (#137)
$bodyClass       = 'page-account page-auth-callback';
$pageCSS         = [];
$pageJS          = [];

require_once CUERCODE_INCLUDES_DIR . 'header.php';
?>

    <!-- ====================================================================
         ⚠️ SSO Callback - Accessible Error Page
         ==================================================================== -->
    <section class="py-5">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-md-8 col-lg-6">
                    <div class="card shadow-sm">
                        <div class="card-body p-4 text-center">
                            <h1 class="h4 fw-bold mb-3">
                                <i class="fa-solid fa-triangle-exclamation me-2 text-warning" aria-hidden="true"></i>
                                Sign-in could not be completed
                            </h1>
                            <div class="alert alert-danger" role="alert">
                                <?php echo htmlspecialchars((string) $errorMessage, ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                            <div class="d-flex justify-content-center gap-2 flex-wrap">
                                <a href="/login/" class="btn btn-primary">
                                    <i class="fa-solid fa-right-to-bracket me-1" aria-hidden="true"></i> Back to sign in
                                </a>
                                <a href="/account/" class="btn btn-outline-secondary">
                                    <i class="fa-solid fa-user me-1" aria-hidden="true"></i> My account
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

<?php
require_once CUERCODE_INCLUDES_DIR . 'footer.php';
