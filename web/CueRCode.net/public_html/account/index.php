<?php
/**
 * ==============================================================================
 * 👤 CueRCode - Account Dashboard (Epic E3 stage 2b, #52/#23/#24)
 * ==============================================================================
 *
 * Path: public_html/account/index.php
 *
 * The logged-in account dashboard: profile (display name + avatar per
 * ProjectBrief §11.4), the saved-codes library (list/preview/download/
 * delete + plan usage indicator), active-session management (list/revoke/
 * sign-out-everywhere), a change-password form, and sign-out.
 *
 * Also hosts a **"Your data"** section (GDPR/CCPA data-subject rights, #37,
 * epic #52): a plain "Download my data" link (a browser-navigable download,
 * not an AJAX call - see public_html/account/export/index.php) and a
 * "Delete my account" erasure flow requiring the current password, an
 * "I understand this is irreversible" checkbox, AND typing the literal word
 * DELETE - see eraseUserAccount() (fn_api_account.php) for exactly what is
 * anonymized vs. retained. A successful erasure redirects to /login/?erased=1
 * (there is no "stay on this page" outcome - the account no longer has
 * anything left here to show).
 *
 * Also hosts the self-service **API keys** section (Epic E7 stage 1, #54):
 * create (name + scope checkboxes, full key shown ONCE with a copy affordance
 * and a role="alert" one-time-secret warning), list (prefix/name/scopes/
 * last-used/rotate/revoke), respecting the plan `api_keys` cap. Every action
 * has BOTH a no-JS <form> fallback (calling the SAME engine functions in
 * fn_api_keys.php the JSON API handlers call into) and a JS-enhanced AJAX
 * path - identical convention to every other section on this page.
 *
 * Requires authentication - an anonymous visitor is redirected to
 * /login/?next=/account/ before any account data is touched.
 *
 * Every mutating action on this page (profile update, avatar upload/remove,
 * password change, session revoke, saved-code delete, sign-out) has BOTH:
 *   - A no-JS <form method="POST"> fallback handled directly on this page,
 *     calling the SAME engine functions (fn_api_account.php / fn_auth.php)
 *     the JSON API handlers call into, gated by validateCSRFToken($_POST).
 *   - A JS-enhanced AJAX path (cuercode-account-dashboard.js) calling the
 *     corresponding /api/v1/... JSON endpoint with the X-CSRF-Token header,
 *     for a no-reload experience.
 *
 * OWN-SCOPED SECURITY: every action below operates ONLY on
 * $currentUserId (resolved from currentUser(), i.e. the visitor's OWN
 * session) - there is no field on this page that can target another
 * account's data. Saved-code ownership is re-checked via
 * qrCodeIsWritableBy()/fetchQrCodeRowByUuid() (fn_api_qrcodes.php) before any
 * delete, exactly like the JSON API does.
 *
 * @package     CueRCode
 * @subpackage  Pages / Account
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2 (Epic E3 stage 2b, #52/#23/#24)
 * @see         _functions/fn_auth.php         - currentUser(), logoutCurrentUser(), resolveAvatarUrl()
 * @see         _functions/fn_api_account.php  - profile/avatar/session/password engine functions
 * @see         _functions/fn_api_qrcodes.php  - fetchQrCodeRowByUuid(), qrCodeIsWritableBy()
 * @see         _functions/fn_features.php     - planForUser(), limitFor(), resolveUpgradeUrl()
 * @see         public_html/assets/js/cuercode-account-dashboard.js
 */

// ==============================================================================
// 🚀 Load Application Bootstrap
// ==============================================================================
require_once __DIR__ . '/../../_includes/bootstrap.php';

// ==============================================================================
// 📦 Load account + saved-codes function files
// ==============================================================================
// Not loaded by bootstrap.php by default (only the API router's own file
// list loads them) - mirrors public_html/index.php's own convention of
// require_once-ing its page-specific function files here.
// ==============================================================================
$accountFunctionFiles = [
    'fn_api_qrcodes.php',
    'fn_api_account.php',
    'fn_api_keys.php',
];

foreach ($accountFunctionFiles as $accountFile) {
    $accountFilePath = CUERCODE_FUNCTIONS_DIR . $accountFile;
    if (file_exists($accountFilePath)) {
        require_once $accountFilePath;
    }
}

// ==============================================================================
// 🛡️ requireAuth (UI variant) - redirect to /login/ instead of a 401 JSON reply
// ==============================================================================

$currentUserData = function_exists('currentUser') ? currentUser() : null;

if ($currentUserData === null) {
    header('Location: /login/?next=' . rawurlencode('/account/'));
    exit;
}

$currentUserId = $currentUserData['userID'];

if (isset($_SESSION[CUERCODE_SESSION_TOKEN_KEY])) {
    $currentSessionToken = (string) $_SESSION[CUERCODE_SESSION_TOKEN_KEY];
} else {
    $currentSessionToken = null;
}

// ==============================================================================
// 📤 Handle Non-JS Form POST Actions (Graceful Degradation)
// ==============================================================================
// Each action is distinguished by its own hidden marker field. Every branch
// independently re-validates the CSRF token from $_POST - there is no shared
// "already validated" flag carried between branches.
// ==============================================================================

$profileMessage  = null; // ['success' => bool, 'text' => string]
$passwordMessage = null;
$sessionMessage  = null;
$qrMessage       = null;
$apiKeyMessage   = null;
$ssoMessage      = null; // Epic E2, #55/#19/#20/#28 - linked-provider unlink feedback
$eraseMessage    = null; // #37/#52 - GDPR/CCPA account-erasure feedback (only ever populated on FAILURE - success redirects away)
$logoutRequested = false;

// 📌 Epic E7 stage 1 (#54): holds the FULL raw key from a just-created or
// just-rotated key, rendered ONCE in this same request's response body
// (never via a redirect/query string - that would leak the secret into
// browser history/referrer/server logs). null on every other request.
$oneTimeApiKeySecret = null; // ['id','api_key','prefix','name']

/**
 * 🛡️ Validate the CSRF token from the current $_POST body.
 *
 * FAILS CLOSED (SECURITY.md F-028 hardening): if validateCSRFToken() is
 * somehow undefined (e.g. fn_csrf.php did not load), every POST action on
 * this page is refused rather than silently permitted - matches
 * public_html/auth/index.php's equivalent gate
 * (`!function_exists('validateCSRFToken') || !validateCSRFToken(...)`),
 * which already fails closed. Previously this returned `true` in that
 * branch, which would have waved through EVERY state-changing action on the
 * account dashboard (profile update, password change, session revoke,
 * SSO unlink, API key create/rotate/revoke, ...) with no CSRF check at all -
 * low-likelihood (fn_csrf.php is always loaded by bootstrap.php in every
 * real deployment) but a fail-OPEN default is the wrong posture for a
 * security control.
 *
 * @return bool
 */
function accountPagePostCsrfValid(): bool
{
    if (isset($_POST['csrf_token'])) {
        $token = (string) $_POST['csrf_token'];
    } else {
        $token = '';
    }

    if (!function_exists('validateCSRFToken')) {
        return false;
    }

    return validateCSRFToken($token);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ------------------------------------------------------------------
    // 📝 Profile update (display name + optional avatar upload)
    // ------------------------------------------------------------------
    if (isset($_POST['profile_submit'])) {
        if (!accountPagePostCsrfValid()) {
            $profileMessage = ['success' => false, 'text' => 'Your session has expired. Please reload the page and try again.'];
        } else {
            $newDisplayName = isset($_POST['display_name']) ? trim((string) $_POST['display_name']) : '';

            if (mb_strlen($newDisplayName) > CUERCODE_ACCOUNT_DISPLAY_NAME_MAX_LEN) {
                $profileMessage = ['success' => false, 'text' => 'Display name is too long (maximum ' . CUERCODE_ACCOUNT_DISPLAY_NAME_MAX_LEN . ' characters).'];
            } else {
                $displayNameOk = updateUserDisplayName($currentUserId, $newDisplayName);
                $avatarOk      = true;
                $avatarError   = null;

                // 🖼️ An avatar file is optional on this form - only process
                // it when the visitor actually chose one.
                if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $avatarValidation = validateAvatarFileUpload($_FILES['avatar']);

                    if (!$avatarValidation['valid']) {
                        $avatarOk    = false;
                        $avatarError = $avatarValidation['error'];
                    } else {
                        $storedPath = storeUserAvatarFile($currentUserId, (string) $avatarValidation['tmpPath'], (string) $avatarValidation['extension']);

                        if ($storedPath === null || !setUserAvatarPath($currentUserId, $storedPath)) {
                            $avatarOk    = false;
                            $avatarError = 'Your avatar could not be saved. Please try again.';
                        }
                    }
                }

                if ($displayNameOk && $avatarOk) {
                    $profileMessage = ['success' => true, 'text' => 'Profile updated successfully.'];
                    if (function_exists('logActivity')) {
                        logActivity('profile_updated', 'User updated their own profile (no-JS form)', 'user', $currentUserId, []);
                    }
                } else {
                    $profileMessage = ['success' => false, 'text' => $avatarError ?? 'Your profile could not be fully updated. Please try again.'];
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // 🗑️ Remove current avatar
    // ------------------------------------------------------------------
    if (isset($_POST['avatar_remove_submit'])) {
        if (!accountPagePostCsrfValid()) {
            $profileMessage = ['success' => false, 'text' => 'Your session has expired. Please reload the page and try again.'];
        } else {
            deleteUserAvatarFiles($currentUserId);
            if (setUserAvatarPath($currentUserId, null)) {
                $profileMessage = ['success' => true, 'text' => 'Avatar removed.'];
            } else {
                $profileMessage = ['success' => false, 'text' => 'Your avatar could not be removed. Please try again.'];
            }
        }
    }

    // ------------------------------------------------------------------
    // 🔑 Change password
    // ------------------------------------------------------------------
    if (isset($_POST['change_password_submit'])) {
        if (!accountPagePostCsrfValid()) {
            $passwordMessage = ['success' => false, 'text' => 'Your session has expired. Please reload the page and try again.'];
        } else {
            $currentPw = isset($_POST['current_password']) ? (string) $_POST['current_password'] : '';
            $newPw     = isset($_POST['new_password']) ? (string) $_POST['new_password'] : '';
            $confirmPw = isset($_POST['new_password_confirm']) ? (string) $_POST['new_password_confirm'] : '';

            if ($currentPw === '' || $newPw === '') {
                $passwordMessage = ['success' => false, 'text' => 'All password fields are required.'];
            } elseif ($newPw !== $confirmPw) {
                $passwordMessage = ['success' => false, 'text' => 'New passwords do not match.'];
            } else {
                $pwResult = changeUserPassword($currentUserId, $currentPw, $newPw, $currentSessionToken);
                $passwordMessage = ['success' => $pwResult['success'], 'text' => $pwResult['message']];
            }
        }
    }

    // ------------------------------------------------------------------
    // 🚫 Revoke ONE session
    // ------------------------------------------------------------------
    if (isset($_POST['session_revoke_submit'])) {
        if (!accountPagePostCsrfValid()) {
            $sessionMessage = ['success' => false, 'text' => 'Your session has expired. Please reload the page and try again.'];
        } else {
            $targetSessionId = isset($_POST['session_id']) ? trim((string) $_POST['session_id']) : '';

            if (preg_match(CUERCODE_ACCOUNT_SESSION_ID_REGEX, $targetSessionId) === 1 && revokeUserSession($currentUserId, $targetSessionId)) {
                $sessionMessage = ['success' => true, 'text' => 'Session revoked.'];

                // 📌 Revoking the CURRENT device's own session logs this
                // browser out too - clear the local session reference.
                if ($currentSessionToken !== null && hash_equals($targetSessionId, $currentSessionToken)) {
                    unset($_SESSION[CUERCODE_SESSION_TOKEN_KEY]);
                    header('Location: /login/');
                    exit;
                }
            } else {
                $sessionMessage = ['success' => false, 'text' => 'That session could not be found.'];
            }
        }
    }

    // ------------------------------------------------------------------
    // 🚪 Sign out everywhere (revoke ALL sessions, including this one)
    // ------------------------------------------------------------------
    if (isset($_POST['sign_out_everywhere_submit'])) {
        if (accountPagePostCsrfValid()) {
            $allSessions = listUserSessions($currentUserId, $currentSessionToken);
            foreach ($allSessions as $sessionRow) {
                revokeUserSession($currentUserId, $sessionRow['id']);
            }
            if (function_exists('logoutCurrentUser')) {
                logoutCurrentUser();
            }
            if (function_exists('logActivity')) {
                logActivity('sign_out_everywhere', 'User signed out of every session', 'user', $currentUserId, []);
            }
        }
        header('Location: /login/');
        exit;
    }

    // ------------------------------------------------------------------
    // 🗑️ Delete a saved QR code
    // ------------------------------------------------------------------
    if (isset($_POST['delete_qr_submit'])) {
        if (!accountPagePostCsrfValid()) {
            $qrMessage = ['success' => false, 'text' => 'Your session has expired. Please reload the page and try again.'];
        } else {
            $targetUuid = isset($_POST['uuid']) ? trim((string) $_POST['uuid']) : '';

            if (!function_exists('isValidQrUuid') || !isValidQrUuid($targetUuid)) {
                $qrMessage = ['success' => false, 'text' => 'That QR code could not be found.'];
            } else {
                $qrRow = fetchQrCodeRowByUuid($targetUuid);
                $ownerId = ($qrRow !== null && $qrRow['userID'] !== null) ? (int) $qrRow['userID'] : null;

                if ($qrRow === null || !qrCodeIsWritableBy($ownerId, $currentUserId)) {
                    // 🔐 Not found/not owned -> identical message, no disclosure.
                    $qrMessage = ['success' => false, 'text' => 'That QR code could not be found.'];
                } else {
                    try {
                        $deleteStmt = dbQuery(
                            'UPDATE `tblQRCodes` SET `deletedAt` = NOW() WHERE `qrID` = ? AND `userID` = ? AND `deletedAt` IS NULL',
                            'ii',
                            [(int) $qrRow['qrID'], $currentUserId]
                        );
                        $deleteStmt->close();
                        $qrMessage = ['success' => true, 'text' => 'QR code deleted.'];
                        if (function_exists('logActivity')) {
                            logActivity('qr_deleted', 'Deleted saved QR code (no-JS form): ' . $targetUuid, 'qr_code', (int) $qrRow['qrID'], []);
                        }
                    } catch (\Throwable $e) {
                        if (function_exists('logError')) {
                            logError('error', 'No-JS saved QR code delete failed', $e->getMessage());
                        }
                        $qrMessage = ['success' => false, 'text' => 'That QR code could not be deleted. Please try again.'];
                    }
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // 🔑 Create a new API key (Epic E7 stage 1, #54)
    // ------------------------------------------------------------------
    if (isset($_POST['api_key_create_submit'])) {
        if (!accountPagePostCsrfValid()) {
            $apiKeyMessage = ['success' => false, 'text' => 'Your session has expired. Please reload the page and try again.'];
        } else {
            $requestedName   = isset($_POST['api_key_name']) ? (string) $_POST['api_key_name'] : '';
            $requestedScopes = isset($_POST['api_key_scopes']) && is_array($_POST['api_key_scopes']) ? $_POST['api_key_scopes'] : [];

            $createResult = createApiKeyForUser($currentUserId, $requestedName, $requestedScopes);

            if ($createResult['success']) {
                $oneTimeApiKeySecret = $createResult['data'];
                $apiKeyMessage        = ['success' => true, 'text' => $createResult['message']];
            } else {
                $apiKeyMessage = ['success' => false, 'text' => $createResult['message']];
            }
        }
    }

    // ------------------------------------------------------------------
    // 🔄 Rotate an existing API key (Epic E7 stage 1, #54)
    // ------------------------------------------------------------------
    if (isset($_POST['api_key_rotate_submit'])) {
        if (!accountPagePostCsrfValid()) {
            $apiKeyMessage = ['success' => false, 'text' => 'Your session has expired. Please reload the page and try again.'];
        } else {
            $targetApiKeyId = isset($_POST['api_key_id']) ? trim((string) $_POST['api_key_id']) : '';

            if (!isValidApiKeyId($targetApiKeyId)) {
                $apiKeyMessage = ['success' => false, 'text' => 'That API key could not be found.'];
            } else {
                $rotateResult = rotateApiKeyForUser($currentUserId, (int) $targetApiKeyId);

                if ($rotateResult['success']) {
                    $oneTimeApiKeySecret = $rotateResult['data'];
                    $apiKeyMessage        = ['success' => true, 'text' => $rotateResult['message']];
                } else {
                    $apiKeyMessage = ['success' => false, 'text' => $rotateResult['message']];
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // 🚫 Revoke an existing API key (Epic E7 stage 1, #54)
    // ------------------------------------------------------------------
    if (isset($_POST['api_key_revoke_submit'])) {
        if (!accountPagePostCsrfValid()) {
            $apiKeyMessage = ['success' => false, 'text' => 'Your session has expired. Please reload the page and try again.'];
        } else {
            $targetApiKeyId = isset($_POST['api_key_id']) ? trim((string) $_POST['api_key_id']) : '';

            if (isValidApiKeyId($targetApiKeyId) && revokeApiKeyForUser($currentUserId, (int) $targetApiKeyId)) {
                $apiKeyMessage = ['success' => true, 'text' => 'API key revoked.'];
            } else {
                $apiKeyMessage = ['success' => false, 'text' => 'That API key could not be found.'];
            }
        }
    }

    // ------------------------------------------------------------------
    // 🔗 Unlink a linked SSO provider (Epic E2, #55/#19/#20/#28)
    // ------------------------------------------------------------------
    if (isset($_POST['oauth_unlink_submit'])) {
        if (!accountPagePostCsrfValid()) {
            $ssoMessage = ['success' => false, 'text' => 'Your session has expired. Please reload the page and try again.'];
        } else {
            $targetOauthId = isset($_POST['oauth_id']) ? (int) $_POST['oauth_id'] : 0;

            if ($targetOauthId <= 0) {
                $ssoMessage = ['success' => false, 'text' => 'That linked account could not be found.'];
            } else {
                $unlinkResult = unlinkOAuthProviderForUser($currentUserId, $targetOauthId);
                $ssoMessage   = ['success' => $unlinkResult['success'], 'text' => $unlinkResult['message']];
            }
        }
    }

    // ------------------------------------------------------------------
    // 🗑️ Delete my account (GDPR/CCPA self-service erasure, #37/#52)
    // ------------------------------------------------------------------
    // Requires ALL THREE, independently of eraseUserAccount()'s own
    // password check: (1) the "I understand this is irreversible"
    // checkbox, (2) typing the literal word DELETE (a UI-only safety net -
    // NOT part of eraseUserAccount()'s own parameters, mirrors how this
    // page independently checks "new passwords match" before calling
    // changeUserPassword()), and (3) the current password (checked by
    // eraseUserAccount() itself). On success the browser is redirected to
    // a signed-out landing page - there is no "stay on this page" outcome
    // for a successful erasure, since the account no longer has anything
    // left here to show.
    // ------------------------------------------------------------------
    if (isset($_POST['erase_account_submit'])) {
        if (!accountPagePostCsrfValid()) {
            $eraseMessage = ['success' => false, 'text' => 'Your session has expired. Please reload the page and try again.'];
        } else {
            $eraseAcknowledged = isset($_POST['erase_acknowledge']) && $_POST['erase_acknowledge'] === '1';
            $eraseTypedPhrase  = isset($_POST['erase_confirm_phrase']) ? trim((string) $_POST['erase_confirm_phrase']) : '';
            $erasePassword     = isset($_POST['erase_current_password']) ? (string) $_POST['erase_current_password'] : '';

            if (!$eraseAcknowledged || $eraseTypedPhrase !== 'DELETE') {
                $eraseMessage = [
                    'success' => false,
                    'text'    => 'You must tick the acknowledgement box and type DELETE exactly (in capital letters) to confirm.',
                ];
            } elseif ($erasePassword === '') {
                $eraseMessage = ['success' => false, 'text' => 'Your current password is required to erase your account.'];
            } else {
                $eraseResult = eraseUserAccount($currentUserId, $erasePassword, true);

                if ($eraseResult['success']) {
                    if (function_exists('logoutCurrentUser')) {
                        logoutCurrentUser();
                    }
                    header('Location: /login/?erased=1');
                    exit;
                }

                $eraseMessage = ['success' => false, 'text' => $eraseResult['message']];
            }
        }
    }

    // ------------------------------------------------------------------
    // 🚪 Logout
    // ------------------------------------------------------------------
    if (isset($_POST['logout_submit']) && accountPagePostCsrfValid()) {
        if (function_exists('logoutCurrentUser')) {
            logoutCurrentUser();
        }
        header('Location: /');
        exit;
    }
}

// ==============================================================================
// 🔄 Re-resolve the current user (reflects any profile change just applied)
// ==============================================================================

$currentUserData = currentUser();

if ($currentUserData === null) {
    // 📌 The account's own session was revoked mid-request (e.g. the visitor
    // just revoked their own current session above and the redirect for
    // some reason did not stop execution) - fail safe to the login page.
    header('Location: /login/');
    exit;
}

$displayName = $currentUserData['displayName'] ?? '';
$userEmail   = $currentUserData['email'];
$avatarPath  = $currentUserData['avatarPath'] ?? null;
$avatarUrl   = function_exists('resolveAvatarUrl') ? resolveAvatarUrl($avatarPath, $userEmail, 128) : '/assets/img/branding/avatar-default.svg';

// ==============================================================================
// 💳 Plan usage (Epic E4 framework - planForUser()/limitFor())
// ==============================================================================

$savedCodesLimit = function_exists('limitFor') ? limitFor('saved_codes', $currentUserId) : 0;
$savedCodesCount = function_exists('countSavedQrCodesForUser') ? countSavedQrCodesForUser($currentUserId) : 0;
$atSavedCodesCap = ($savedCodesLimit !== -1 && $savedCodesCount >= $savedCodesLimit);
$upgradeUrl      = function_exists('resolveUpgradeUrl') ? resolveUpgradeUrl() : '/pricing';

// 🔑 API keys plan usage (Epic E7 stage 1, #54 - api_keys limit: anonymous=0,
// free=1, pro=5, business=20 - seed_data.sql).
$apiKeysLimit       = function_exists('limitFor') ? limitFor('api_keys', $currentUserId) : 0;
$apiKeysActiveCount = function_exists('countActiveApiKeysForUser') ? countActiveApiKeysForUser($currentUserId) : 0;
$atApiKeysCap       = ($apiKeysLimit !== -1 && $apiKeysActiveCount >= $apiKeysLimit);
$userApiKeys        = function_exists('listApiKeysForUser') ? listApiKeysForUser($currentUserId) : [];

// ==============================================================================
// 📚 Saved codes library (server-rendered page 1 / ?page=N - no-JS friendly)
// ==============================================================================

$rawPage = isset($_GET['page']) ? $_GET['page'] : 1;
$pagination = function_exists('clampPaginationParams') ? clampPaginationParams($rawPage, 12, 12, 50) : ['page' => 1, 'per_page' => 12, 'offset' => 0];

$savedCodes      = [];
$savedCodesTotal = 0;

try {
    $countStmt = dbQuery(
        'SELECT COUNT(*) AS `total` FROM `tblQRCodes` WHERE `userID` = ? AND `deletedAt` IS NULL',
        'i',
        [$currentUserId]
    );
    $countRow = $countStmt->get_result()->fetch_assoc();
    $countStmt->close();
    $savedCodesTotal = $countRow !== null ? (int) $countRow['total'] : 0;

    $listStmt = dbQuery(
        'SELECT q.`qrUUID`, t.`typeName`, q.`qrLabel`, q.`qrOutputFormat`, q.`isPublic`, '
        . 'q.`downloadCount`, q.`createdAt` '
        . 'FROM `tblQRCodes` q '
        . 'LEFT JOIN `tblQRCodeTypes` t ON q.`qrTypeID` = t.`typeID` '
        . 'WHERE q.`userID` = ? AND q.`deletedAt` IS NULL '
        . 'ORDER BY q.`createdAt` DESC '
        . 'LIMIT ? OFFSET ?',
        'iii',
        [$currentUserId, $pagination['per_page'], $pagination['offset']]
    );
    $listResult = $listStmt->get_result();
    while ($row = $listResult->fetch_assoc()) {
        $savedCodes[] = $row;
    }
    $listStmt->close();
} catch (\Throwable $e) {
    if (function_exists('logError')) {
        logError('error', 'Account page saved-codes list failed', 'userID: ' . $currentUserId . ' | ' . $e->getMessage());
    }
}

$savedCodesTotalPages = $pagination['per_page'] > 0 ? (int) ceil($savedCodesTotal / $pagination['per_page']) : 0;

// ==============================================================================
// 🔐 Active sessions
// ==============================================================================

$activeSessions = listUserSessions($currentUserId, $currentSessionToken);

// ==============================================================================
// 🔗 Linked SSO providers (Epic E2, #55/#19/#20/#28)
// ==============================================================================
// Every provider is listed either as "linked" (with an unlink form) or as an
// available "Link" button - but the "Link" button is rendered ONLY when that
// provider is enabled+configured (ssoProviderIsUsable()), same gating as the
// login/register page buttons.
// ==============================================================================

$linkedSsoProviders = listLinkedOAuthProvidersForUser($currentUserId);

$linkedSsoProviderKeys = [];
foreach ($linkedSsoProviders as $linkedProviderRow) {
    $linkedSsoProviderKeys[] = $linkedProviderRow['provider'];
}

$availableSsoProviders = [];
foreach (ssoSupportedProviders() as $ssoProviderKey) {
    if (in_array($ssoProviderKey, $linkedSsoProviderKeys, true)) {
        continue;
    }

    $ssoProviderMeta = ssoProviderUiMeta($ssoProviderKey);

    if ($ssoProviderMeta['usable']) {
        $availableSsoProviders[] = $ssoProviderMeta;
    }
}

// 🛡️ Never let the UI offer to unlink the account's LAST sign-in method -
// mirrors unlinkOAuthProviderForUser()'s own server-side enforcement.
$accountHasPasswordSet = userHasPasswordSet($currentUserId);

// ==============================================================================
// ⚙️ Page Variables
// ==============================================================================

$pageTitle       = 'My Account';
$pageDescription = 'Manage your CueRCode profile, saved QR codes, and account security.';
$pageNoIndex     = true; // 🚫 Keep out of search results (#137)
$bodyClass       = 'page-account page-account-dashboard';
$pageCSS         = [];
$pageJS          = ['/assets/js/cuercode-account-dashboard.js'];

// ==============================================================================
// 📄 Render Page
// ==============================================================================

require_once CUERCODE_INCLUDES_DIR . 'header.php';
?>

    <?php /* ====================================================================
         👤 Account Dashboard
         ==================================================================== */ ?>
    <section class="py-4">
        <div class="container">
            <h1 class="h3 fw-bold mb-4">
                My account
            </h1>

            <?php /* 📑 On-page section navigation (mirrors the accessibility guide's convention) */ ?>
            <nav aria-label="Account sections" class="mb-4">
                <ul class="nav nav-pills">
                    <li class="nav-item"><a class="nav-link" href="#profile-section">Profile</a></li>
                    <li class="nav-item"><a class="nav-link" href="#library-section">Saved codes</a></li>
                    <li class="nav-item"><a class="nav-link" href="#security-section">Security</a></li>
                    <li class="nav-item"><a class="nav-link" href="#sso-section">Linked accounts</a></li>
                    <li class="nav-item"><a class="nav-link" href="#api-keys-section">API keys</a></li>
                    <li class="nav-item"><a class="nav-link" href="#data-section">Your data</a></li>
                </ul>
            </nav>

            <?php /* ================================================================
                 📝 Profile Section
                 ================================================================ */ ?>
            <div class="card shadow-sm mb-4" id="profile-section">
                <div class="card-header bg-transparent">
                    <h2 class="h5 mb-0"><i class="fa-solid fa-id-card me-2" aria-hidden="true"></i>Profile</h2>
                </div>
                <div class="card-body">
                    <?php if ($profileMessage !== null) { ?>
                    <div class="alert <?php echo $profileMessage['success'] ? 'alert-success' : 'alert-danger'; ?>"
                         role="<?php echo $profileMessage['success'] ? 'status' : 'alert'; ?>" id="profile-message">
                        <i class="fa-solid <?php echo $profileMessage['success'] ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?> me-1" aria-hidden="true"></i>
                        <?php echo htmlspecialchars($profileMessage['text'], ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <?php } ?>
                    <div id="profile-ajax-alert" class="alert d-none" role="alert" aria-live="assertive"></div>

                    <div class="row g-4 align-items-start">
                        <div class="col-md-3 text-center">
                            <img src="<?php echo htmlspecialchars($avatarUrl, ENT_QUOTES, 'UTF-8'); ?>"
                                 alt="Your avatar" class="rounded-circle mb-2" width="128" height="128"
                                 id="account-avatar-preview"
                                 onerror="this.onerror=null;this.src='/assets/img/branding/avatar-default.svg';">
                            <?php if ($avatarPath !== null && trim((string) $avatarPath) !== '') { ?>
                            <form method="POST" action="/account/#profile-section">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="avatar_remove_submit" value="1">
                                <button type="submit" class="btn btn-sm btn-outline-danger" id="avatar-remove-btn">
                                    <i class="fa-solid fa-trash me-1" aria-hidden="true"></i> Remove avatar
                                </button>
                            </form>
                            <?php } ?>
                            <p class="form-text mt-2 mb-0">
                                Avatar priority: your upload &gt; Gravatar &gt; default icon.
                            </p>
                        </div>
                        <div class="col-md-9">
                            <form method="POST" action="/account/#profile-section" enctype="multipart/form-data" id="profile-form">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="profile_submit" value="1">

                                <div class="mb-3">
                                    <label for="profile-email" class="form-label">Email address</label>
                                    <input type="email" class="form-control" id="profile-email" value="<?php echo htmlspecialchars($userEmail, ENT_QUOTES, 'UTF-8'); ?>" disabled>
                                    <div class="form-text">Email address changes are not yet self-service.</div>
                                </div>

                                <div class="mb-3">
                                    <label for="profile-display-name" class="form-label">Display name</label>
                                    <input type="text" class="form-control" id="profile-display-name" name="display_name"
                                           value="<?php echo htmlspecialchars((string) $displayName, ENT_QUOTES, 'UTF-8'); ?>"
                                           maxlength="100">
                                </div>

                                <div class="mb-3">
                                    <label for="profile-avatar" class="form-label">Upload a new avatar <span class="text-body-secondary">(PNG, JPEG, GIF, or WebP - max 2&nbsp;MB)</span></label>
                                    <input type="file" class="form-control" id="profile-avatar" name="avatar"
                                           accept="image/png,image/jpeg,image/gif,image/webp">
                                </div>

                                <button type="submit" class="btn btn-primary" id="profile-submit-btn">
                                    <i class="fa-solid fa-floppy-disk me-1" aria-hidden="true"></i> Save changes
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <?php /* ================================================================
                 📚 Saved Codes Library Section
                 ================================================================ */ ?>
            <div class="card shadow-sm mb-4" id="library-section">
                <div class="card-header bg-transparent d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h2 class="h5 mb-0"><i class="fa-solid fa-layer-group me-2" aria-hidden="true"></i>Saved codes</h2>
                    <span class="badge <?php echo $atSavedCodesCap ? 'text-bg-warning' : 'text-bg-secondary'; ?>" id="saved-codes-usage-badge">
                        <?php // 🔢 The number sits in its own span so the dashboard script can lower it after a delete ?>
                        <span id="saved-codes-count"><?php echo htmlspecialchars((string) $savedCodesCount, ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php
                        echo ' / ';
                        echo $savedCodesLimit === -1
                            ? 'Unlimited'
                            : htmlspecialchars((string) $savedCodesLimit, ENT_QUOTES, 'UTF-8');
                        ?> saved
                    </span>
                </div>
                <div class="card-body">
                    <?php if ($qrMessage !== null) { ?>
                    <div class="alert <?php echo $qrMessage['success'] ? 'alert-success' : 'alert-danger'; ?>"
                         role="<?php echo $qrMessage['success'] ? 'status' : 'alert'; ?>" id="library-message">
                        <i class="fa-solid <?php echo $qrMessage['success'] ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?> me-1" aria-hidden="true"></i>
                        <?php echo htmlspecialchars($qrMessage['text'], ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <?php } ?>
                    <div id="library-ajax-alert" class="alert d-none" role="alert" aria-live="assertive"></div>

                    <?php if ($atSavedCodesCap) { ?>
                    <?php /* ♿ Upgrade prompt: visible/announced, never silently hidden */ ?>
                    <div class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap gap-2" role="alert">
                        <span>
                            <i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i>
                            You have reached your plan's saved-codes limit.
                        </span>
                        <a href="<?php echo htmlspecialchars($upgradeUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-outline-primary">
                            See plans
                        </a>
                    </div>
                    <?php } ?>

                    <?php
                    // 📭 Empty state for the library. It is ALWAYS in the page: visible
                    // when there are no saved codes, hidden (d-none) otherwise. That way
                    // cuercode-account-dashboard.js can reveal this very same block the
                    // moment the LAST code is deleted, with no reload and no second copy
                    // of the wording to keep in step.
                    $savedCodesEmptyClass = 'text-center py-4';
                    if (!empty($savedCodes)) {
                        $savedCodesEmptyClass = $savedCodesEmptyClass . ' d-none';
                    }
                    ?>
                    <div class="<?php echo $savedCodesEmptyClass; ?>" id="saved-codes-empty" tabindex="-1">
                        <p class="mb-3">
                            <i class="fa-solid fa-qrcode fa-3x text-cuercode-primary opacity-75" aria-hidden="true"></i>
                        </p>
                        <p class="fw-semibold mb-1">You haven't saved any QR codes yet.</p>
                        <p class="text-body-secondary mb-3">
                            Make one on the generator and choose "Save to my account" to keep it here.
                        </p>
                        <a href="/" class="btn btn-primary">
                            <i class="fa-solid fa-qrcode me-1" aria-hidden="true"></i> Create a QR code
                        </a>
                    </div>

                    <?php if (!empty($savedCodes)) { ?>
                    <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-3" id="saved-codes-list">
                        <?php foreach ($savedCodes as $savedCode) { ?>
                        <div class="col">
                            <div class="border rounded p-3 h-100 d-flex flex-column" data-uuid="<?php echo htmlspecialchars((string) $savedCode['qrUUID'], ENT_QUOTES, 'UTF-8'); ?>">
                                <img src="/account/qr-preview/?uuid=<?php echo urlencode((string) $savedCode['qrUUID']); ?>"
                                     alt="Preview of saved QR code<?php echo $savedCode['qrLabel'] !== null ? ': ' . htmlspecialchars((string) $savedCode['qrLabel'], ENT_QUOTES, 'UTF-8') : ''; ?>"
                                     class="img-fluid mb-2 align-self-center" style="max-height: 140px;" loading="lazy">
                                <p class="mb-1 fw-semibold text-truncate">
                                    <?php
                                    if ($savedCode['qrLabel'] !== null && trim((string) $savedCode['qrLabel']) !== '') {
                                        echo htmlspecialchars((string) $savedCode['qrLabel'], ENT_QUOTES, 'UTF-8');
                                    } else {
                                        echo htmlspecialchars((string) ($savedCode['typeName'] ?? 'QR code'), ENT_QUOTES, 'UTF-8');
                                    }
                                    ?>
                                </p>
                                <p class="small text-body-secondary mb-2">
                                    <?php echo htmlspecialchars(strtoupper((string) $savedCode['qrOutputFormat']), ENT_QUOTES, 'UTF-8'); ?>
                                    &middot; Saved <?php echo htmlspecialchars(date('j M Y', strtotime((string) $savedCode['createdAt'])), ENT_QUOTES, 'UTF-8'); ?>
                                    &middot; <?php echo (int) $savedCode['downloadCount']; ?> download<?php echo ((int) $savedCode['downloadCount'] === 1) ? '' : 's'; ?>
                                </p>
                                <div class="mt-auto d-flex gap-2 flex-wrap">
                                    <a class="btn btn-sm btn-outline-primary"
                                       href="/account/qr-preview/?uuid=<?php echo urlencode((string) $savedCode['qrUUID']); ?>&amp;download=1">
                                        <i class="fa-solid fa-download me-1" aria-hidden="true"></i> Download
                                    </a>
                                    <form method="POST" action="/account/#library-section" class="d-inline delete-qr-form">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="delete_qr_submit" value="1">
                                        <input type="hidden" name="uuid" value="<?php echo htmlspecialchars((string) $savedCode['qrUUID'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            <i class="fa-solid fa-trash me-1" aria-hidden="true"></i> Delete
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <?php } ?>
                    </div>

                    <?php if ($savedCodesTotalPages > 1) { ?>
                    <nav aria-label="Saved codes pagination" class="mt-3">
                        <ul class="pagination justify-content-center flex-wrap">
                            <?php for ($pageNum = 1; $pageNum <= $savedCodesTotalPages; $pageNum++) { ?>
                            <li class="page-item <?php echo ($pageNum === $pagination['page']) ? 'active' : ''; ?>">
                                <a class="page-link" href="/account/?page=<?php echo (int) $pageNum; ?>#library-section"
                                   <?php echo ($pageNum === $pagination['page']) ? 'aria-current="page"' : ''; ?>>
                                    <?php echo (int) $pageNum; ?>
                                </a>
                            </li>
                            <?php } ?>
                        </ul>
                    </nav>
                    <?php } ?>
                    <?php } ?>
                </div>
            </div>

            <?php /* ================================================================
                 🔐 Security / Sessions Section
                 ================================================================ */ ?>
            <div class="card shadow-sm mb-4" id="security-section">
                <div class="card-header bg-transparent">
                    <h2 class="h5 mb-0"><i class="fa-solid fa-shield-halved me-2" aria-hidden="true"></i>Security</h2>
                </div>
                <div class="card-body">
                    <?php if ($passwordMessage !== null) { ?>
                    <div class="alert <?php echo $passwordMessage['success'] ? 'alert-success' : 'alert-danger'; ?>"
                         role="<?php echo $passwordMessage['success'] ? 'status' : 'alert'; ?>" id="password-message">
                        <i class="fa-solid <?php echo $passwordMessage['success'] ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?> me-1" aria-hidden="true"></i>
                        <?php echo htmlspecialchars($passwordMessage['text'], ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <?php } ?>
                    <div id="password-ajax-alert" class="alert d-none" role="alert" aria-live="assertive"></div>

                    <h3 class="h6 fw-bold">Change password</h3>
                    <form method="POST" action="/account/#security-section" class="row g-3 mb-4" id="change-password-form">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="change_password_submit" value="1">
                        <div class="col-md-4">
                            <label for="cp-current" class="form-label">Current password</label>
                            <input type="password" class="form-control" id="cp-current" name="current_password" autocomplete="current-password" required maxlength="256">
                        </div>
                        <div class="col-md-4">
                            <label for="cp-new" class="form-label">New password</label>
                            <input type="password" class="form-control" id="cp-new" name="new_password" autocomplete="new-password" required minlength="8" maxlength="256">
                        </div>
                        <div class="col-md-4">
                            <label for="cp-confirm" class="form-label">Confirm new password</label>
                            <input type="password" class="form-control" id="cp-confirm" name="new_password_confirm" autocomplete="new-password" required minlength="8" maxlength="256">
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary" id="change-password-btn">
                                <i class="fa-solid fa-key me-1" aria-hidden="true"></i> Change password
                            </button>
                        </div>
                    </form>

                    <?php if ($sessionMessage !== null) { ?>
                    <div class="alert <?php echo $sessionMessage['success'] ? 'alert-success' : 'alert-danger'; ?>"
                         role="<?php echo $sessionMessage['success'] ? 'status' : 'alert'; ?>" id="session-message">
                        <i class="fa-solid <?php echo $sessionMessage['success'] ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?> me-1" aria-hidden="true"></i>
                        <?php echo htmlspecialchars($sessionMessage['text'], ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <?php } ?>
                    <div id="session-ajax-alert" class="alert d-none" role="alert" aria-live="assertive"></div>

                    <h3 class="h6 fw-bold d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <span>Active sessions</span>
                        <form method="POST" action="/account/#security-section" id="sign-out-everywhere-form">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="sign_out_everywhere_submit" value="1">
                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                <i class="fa-solid fa-right-from-bracket me-1" aria-hidden="true"></i> Sign out everywhere
                            </button>
                        </form>
                    </h3>

                    <?php if (empty($activeSessions)) { ?>
                    <p class="text-body-secondary">No active sessions found.</p>
                    <?php } else { ?>
                    <ul class="list-group mb-0" id="sessions-list">
                        <?php foreach ($activeSessions as $sessionItem) { ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center flex-wrap gap-2"
                            data-session-id="<?php echo htmlspecialchars($sessionItem['id'], ENT_QUOTES, 'UTF-8'); ?>">
                            <div>
                                <div>
                                    <?php if ($sessionItem['is_current']) { ?>
                                    <span class="badge text-bg-success me-1">This device</span>
                                    <?php } ?>
                                    <code><?php echo htmlspecialchars($sessionItem['masked_id'], ENT_QUOTES, 'UTF-8'); ?></code>
                                </div>
                                <small class="text-body-secondary">
                                    <?php echo htmlspecialchars((string) ($sessionItem['ip'] ?? 'Unknown IP'), ENT_QUOTES, 'UTF-8'); ?>
                                    &middot;
                                    <?php echo htmlspecialchars((string) ($sessionItem['user_agent'] ?? 'Unknown device'), ENT_QUOTES, 'UTF-8'); ?>
                                    &middot; Last seen <?php echo htmlspecialchars((string) $sessionItem['last_seen_at'], ENT_QUOTES, 'UTF-8'); ?>
                                </small>
                            </div>
                            <form method="POST" action="/account/#security-section" class="revoke-session-form">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="session_revoke_submit" value="1">
                                <input type="hidden" name="session_id" value="<?php echo htmlspecialchars($sessionItem['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                <button type="submit" class="btn btn-sm btn-outline-secondary">
                                    <i class="fa-solid fa-xmark me-1" aria-hidden="true"></i> Revoke
                                </button>
                            </form>
                        </li>
                        <?php } ?>
                    </ul>
                    <?php } ?>
                </div>
            </div>

            <?php /* ================================================================
                 🔗 Linked Accounts / SSO Section (Epic E2, #55/#19/#20/#28)
                 ================================================================ */ ?>
            <div class="card shadow-sm mb-4" id="sso-section">
                <div class="card-header bg-transparent">
                    <h2 class="h5 mb-0"><i class="fa-solid fa-link me-2" aria-hidden="true"></i>Linked accounts</h2>
                </div>
                <div class="card-body">
                    <p class="text-body-secondary">
                        Sign in faster by linking a Microsoft 365 or Google account. Every SSO identity is linked to
                        <strong>this</strong> CueRCode account only - it is never used to auto-link a different account, even if the
                        email addresses match.
                    </p>

                    <?php if ($ssoMessage !== null) { ?>
                    <div class="alert <?php echo $ssoMessage['success'] ? 'alert-success' : 'alert-danger'; ?>"
                         role="<?php echo $ssoMessage['success'] ? 'status' : 'alert'; ?>" id="sso-message">
                        <i class="fa-solid <?php echo $ssoMessage['success'] ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?> me-1" aria-hidden="true"></i>
                        <?php echo htmlspecialchars($ssoMessage['text'], ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <?php } ?>

                    <h3 class="h6 fw-bold">Currently linked</h3>
                    <?php if (empty($linkedSsoProviders)) { ?>
                    <p class="text-body-secondary" id="sso-linked-list-empty">No providers linked yet.</p>
                    <?php } else { ?>
                    <ul class="list-group mb-3" id="sso-linked-list">
                        <?php foreach ($linkedSsoProviders as $linkedProvider) { ?>
                        <?php
                        // 🛡️ Never offer to unlink the account's LAST sign-in
                        // method (no password set AND this is the only linked
                        // provider) - mirrors unlinkOAuthProviderForUser()'s
                        // own server-side enforcement, rendered here too so
                        // the control is not misleadingly shown as available.
                        $isOnlyCredential = (!$accountHasPasswordSet && count($linkedSsoProviders) <= 1);
                        ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center flex-wrap gap-2"
                            data-oauth-id="<?php echo (int) $linkedProvider['id']; ?>">
                            <div>
                                <i class="<?php echo htmlspecialchars(ssoProviderIconClass($linkedProvider['provider']), ENT_QUOTES, 'UTF-8'); ?> me-1" aria-hidden="true"></i>
                                <strong><?php echo htmlspecialchars($linkedProvider['display_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                <div class="small text-body-secondary">
                                    <code><?php echo htmlspecialchars($linkedProvider['masked_subject'], ENT_QUOTES, 'UTF-8'); ?></code>
                                    &middot; Linked <?php echo htmlspecialchars(date('j M Y', strtotime($linkedProvider['linked_at'])), ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                            </div>
                            <?php if ($isOnlyCredential) { ?>
                            <span class="badge text-bg-secondary" title="Set a password before unlinking your only sign-in method">
                                Only sign-in method
                            </span>
                            <?php } else { ?>
                            <form method="POST" action="/account/#sso-section" class="unlink-sso-form">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="oauth_unlink_submit" value="1">
                                <input type="hidden" name="oauth_id" value="<?php echo (int) $linkedProvider['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                    <i class="fa-solid fa-link-slash me-1" aria-hidden="true"></i> Unlink
                                </button>
                            </form>
                            <?php } ?>
                        </li>
                        <?php } ?>
                    </ul>
                    <?php } ?>

                    <?php if (!empty($availableSsoProviders)) { ?>
                    <h3 class="h6 fw-bold">Link another provider</h3>
                    <div class="d-flex gap-2 flex-wrap" id="sso-link-buttons">
                        <?php foreach ($availableSsoProviders as $availableProvider) { ?>
                        <form method="POST" action="/auth/<?php echo rawurlencode($availableProvider['provider']); ?>" class="d-inline">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="next" value="/account/#sso-section">
                            <button type="submit" class="btn btn-outline-secondary btn-sm">
                                <i class="<?php echo htmlspecialchars($availableProvider['iconClass'], ENT_QUOTES, 'UTF-8'); ?> me-1" aria-hidden="true"></i>
                                Link <?php echo htmlspecialchars($availableProvider['displayName'], ENT_QUOTES, 'UTF-8'); ?>
                            </button>
                        </form>
                        <?php } ?>
                    </div>
                    <?php } ?>
                </div>
            </div>

            <?php /* ================================================================
                 🔑 API Keys Section (Epic E7 stage 1, #54)
                 ================================================================ */ ?>
            <div class="card shadow-sm mb-4" id="api-keys-section">
                <div class="card-header bg-transparent d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h2 class="h5 mb-0"><i class="fa-solid fa-key me-2" aria-hidden="true"></i>API keys</h2>
                    <span class="badge <?php echo $atApiKeysCap ? 'text-bg-warning' : 'text-bg-secondary'; ?>" id="api-keys-usage-badge">
                        <?php
                        echo htmlspecialchars((string) $apiKeysActiveCount, ENT_QUOTES, 'UTF-8');
                        echo ' / ';
                        echo $apiKeysLimit === -1
                            ? 'Unlimited'
                            : htmlspecialchars((string) $apiKeysLimit, ENT_QUOTES, 'UTF-8');
                        ?> active
                    </span>
                </div>
                <div class="card-body">
                    <p class="text-body-secondary">
                        API keys let external tools (scripts, partner integrations, a future companion app)
                        call the CueRCode API on your behalf. Grant a key only the scopes an integration
                        actually needs.
                    </p>

                    <?php if ($apiKeyMessage !== null) { ?>
                    <div class="alert <?php echo $apiKeyMessage['success'] ? 'alert-success' : 'alert-danger'; ?>"
                         role="<?php echo $apiKeyMessage['success'] ? 'status' : 'alert'; ?>" id="api-key-message">
                        <i class="fa-solid <?php echo $apiKeyMessage['success'] ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?> me-1" aria-hidden="true"></i>
                        <?php echo htmlspecialchars($apiKeyMessage['text'], ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <?php } ?>
                    <div id="api-key-ajax-alert" class="alert d-none" role="alert" aria-live="assertive"></div>

                    <?php if ($oneTimeApiKeySecret !== null) { ?>
                    <?php /* ♿ role="alert": a one-time secret must be impossible to miss and is NEVER retrievable again. */ ?>
                    <div class="alert alert-warning" role="alert" id="api-key-one-time-secret">
                        <p class="fw-bold mb-1">
                            <i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i>
                            Copy this key now - you will not be able to see it again.
                        </p>
                        <div class="input-group">
                            <input type="text" class="form-control font-monospace" readonly
                                   id="api-key-one-time-value"
                                   value="<?php echo htmlspecialchars((string) $oneTimeApiKeySecret['api_key'], ENT_QUOTES, 'UTF-8'); ?>"
                                   aria-label="Your new API key - copy it now">
                            <button type="button" class="btn btn-outline-secondary" id="api-key-copy-btn"
                                    data-copy-target="api-key-one-time-value" aria-label="Copy API key to clipboard">
                                <i class="fa-solid fa-copy me-1" aria-hidden="true"></i> Copy
                            </button>
                        </div>
                        <p class="form-text mb-0 mt-1">
                            Name: <strong><?php echo htmlspecialchars((string) $oneTimeApiKeySecret['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                            &middot; Prefix: <code><?php echo htmlspecialchars((string) $oneTimeApiKeySecret['prefix'], ENT_QUOTES, 'UTF-8'); ?></code>
                        </p>
                    </div>
                    <?php } ?>

                    <?php if ($atApiKeysCap) { ?>
                    <?php /* ♿ Upgrade prompt: visible/announced, never silently hidden */ ?>
                    <div class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap gap-2" role="alert">
                        <span>
                            <i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i>
                            You have reached your plan's API key limit.
                        </span>
                        <a href="<?php echo htmlspecialchars($upgradeUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-outline-primary">
                            See plans
                        </a>
                    </div>
                    <?php } else { ?>
                    <form method="POST" action="/account/#api-keys-section" class="row g-3 mb-4" id="api-key-create-form">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="api_key_create_submit" value="1">
                        <div class="col-md-6">
                            <label for="api-key-name" class="form-label">Key name</label>
                            <input type="text" class="form-control" id="api-key-name" name="api_key_name" maxlength="100" required
                                   placeholder="e.g. My CMS integration">
                        </div>
                        <div class="col-12">
                            <fieldset>
                                <legend class="form-label h6">Scopes</legend>
                                <?php foreach (CUERCODE_API_KEY_SCOPE_ALLOWLIST as $scopeOption) { ?>
                                <?php $scopeCheckboxId = 'api-key-scope-' . str_replace(':', '-', $scopeOption); ?>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="checkbox" name="api_key_scopes[]"
                                           id="<?php echo htmlspecialchars($scopeCheckboxId, ENT_QUOTES, 'UTF-8'); ?>"
                                           value="<?php echo htmlspecialchars($scopeOption, ENT_QUOTES, 'UTF-8'); ?>">
                                    <label class="form-check-label" for="<?php echo htmlspecialchars($scopeCheckboxId, ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars(apiKeyScopeLabel($scopeOption), ENT_QUOTES, 'UTF-8'); ?>
                                    </label>
                                </div>
                                <?php } ?>
                                <div class="form-text">A key with no scopes selected can authenticate but cannot access any scoped endpoint.</div>
                            </fieldset>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary" id="api-key-create-btn">
                                <i class="fa-solid fa-plus me-1" aria-hidden="true"></i> Create key
                            </button>
                        </div>
                    </form>
                    <?php } ?>

                    <h3 class="h6 fw-bold">Your keys</h3>
                    <?php if (empty($userApiKeys)) { ?>
                    <?php /* 📭 Empty state. cuercode-account-dashboard.js renders the same markup
                             (renderApiKeysList) if the list ever becomes empty again. */ ?>
                    <div class="text-center py-4" id="api-keys-list-empty">
                        <p class="mb-3">
                            <i class="fa-solid fa-key fa-2x text-cuercode-primary opacity-75" aria-hidden="true"></i>
                        </p>
                        <p class="fw-semibold mb-1">You have not created any API keys yet.</p>
                        <p class="text-body-secondary mb-3">
                            An API key lets your own software create QR codes through the CueRCode API.
                        </p>
                        <?php if (!$atApiKeysCap) { ?>
                        <a href="#api-key-name" class="btn btn-primary">
                            <i class="fa-solid fa-plus me-1" aria-hidden="true"></i> Name your first key
                        </a>
                        <?php } ?>
                    </div>
                    <?php } else { ?>
                    <div class="d-flex flex-column gap-2" id="api-keys-list">
                        <?php foreach ($userApiKeys as $apiKeyItem) { ?>
                        <div class="border rounded p-3" data-api-key-id="<?php echo (int) $apiKeyItem['id']; ?>">
                            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                                <div>
                                    <div class="fw-semibold">
                                        <?php echo htmlspecialchars((string) $apiKeyItem['name'], ENT_QUOTES, 'UTF-8'); ?>
                                        <?php if ($apiKeyItem['is_active']) { ?>
                                        <span class="badge text-bg-success ms-1">Active</span>
                                        <?php } else { ?>
                                        <span class="badge text-bg-secondary ms-1">Revoked</span>
                                        <?php } ?>
                                    </div>
                                    <div class="small text-body-secondary">
                                        <code><?php echo htmlspecialchars((string) $apiKeyItem['prefix'], ENT_QUOTES, 'UTF-8'); ?>&hellip;</code>
                                        &middot; Created <?php echo htmlspecialchars(date('j M Y', strtotime((string) $apiKeyItem['created_at'])), ENT_QUOTES, 'UTF-8'); ?>
                                        &middot; Last used
                                        <?php
                                        if ($apiKeyItem['last_used_at'] !== null) {
                                            echo htmlspecialchars(date('j M Y H:i', strtotime((string) $apiKeyItem['last_used_at'])), ENT_QUOTES, 'UTF-8');
                                        } else {
                                            echo 'never';
                                        }
                                        ?>
                                        &middot; <?php echo (int) $apiKeyItem['rate_limits']['per_min']; ?>/min, <?php echo (int) $apiKeyItem['rate_limits']['per_day']; ?>/day
                                    </div>
                                    <div class="mt-1">
                                        <?php if (empty($apiKeyItem['scopes'])) { ?>
                                        <span class="badge text-bg-light border text-body-secondary">No scopes granted</span>
                                        <?php } else { ?>
                                        <?php foreach ($apiKeyItem['scopes'] as $grantedScope) { ?>
                                        <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle me-1"><?php echo htmlspecialchars($grantedScope, ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php } ?>
                                        <?php } ?>
                                    </div>
                                </div>
                                <?php if ($apiKeyItem['is_active']) { ?>
                                <div class="d-flex gap-2 flex-wrap">
                                    <form method="POST" action="/account/#api-keys-section" class="d-inline rotate-api-key-form">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="api_key_rotate_submit" value="1">
                                        <input type="hidden" name="api_key_id" value="<?php echo (int) $apiKeyItem['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-primary">
                                            <i class="fa-solid fa-arrows-rotate me-1" aria-hidden="true"></i> Rotate
                                        </button>
                                    </form>
                                    <form method="POST" action="/account/#api-keys-section" class="d-inline revoke-api-key-form">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="api_key_revoke_submit" value="1">
                                        <input type="hidden" name="api_key_id" value="<?php echo (int) $apiKeyItem['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            <i class="fa-solid fa-trash me-1" aria-hidden="true"></i> Revoke
                                        </button>
                                    </form>
                                </div>
                                <?php } ?>
                            </div>
                        </div>
                        <?php } ?>
                    </div>
                    <?php } ?>
                </div>
            </div>

            <?php /* ================================================================
                 📦 Your Data Section (GDPR/CCPA data-subject rights, #37/#52)
                 ================================================================ */ ?>
            <div class="card shadow-sm mb-4" id="data-section">
                <div class="card-header bg-transparent">
                    <h2 class="h5 mb-0"><i class="fa-solid fa-database me-2" aria-hidden="true"></i>Your data</h2>
                </div>
                <div class="card-body">
                    <p class="text-body-secondary">
                        Under GDPR, CCPA, and similar privacy laws you have the right to access a copy of your
                        data and to have your account erased. See our
                        <a href="/privacy#rights">Privacy Policy</a> for the full details, including the
                        limited retention exceptions for financial/audit records described below.
                    </p>

                    <?php /* ============================================================
                         📤 Download my data (access / export / portability)
                         ============================================================ */ ?>
                    <h3 class="h6 fw-bold">Download my data</h3>
                    <p class="text-body-secondary">
                        Get a complete, machine-readable (JSON) copy of your profile, saved QR codes,
                        active sessions, subscriptions and payment history, linked sign-in providers, and
                        API keys. Secrets, password hashes, session tokens, and SSO access/refresh tokens are
                        never included.
                    </p>
                    <a href="/account/export/" class="btn btn-outline-primary mb-4" id="download-data-btn">
                        <i class="fa-solid fa-download me-1" aria-hidden="true"></i> Download my data
                    </a>

                    <?php /* ============================================================
                         🗑️ Delete my account (erasure)
                         ============================================================ */ ?>
                    <h3 class="h6 fw-bold text-danger">Delete my account</h3>

                    <?php if ($eraseMessage !== null) { ?>
                    <div class="alert alert-danger" role="alert" id="erase-message">
                        <i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i>
                        <?php echo htmlspecialchars($eraseMessage['text'], ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <?php } ?>

                    <div class="alert alert-warning" role="alert">
                        <p class="fw-bold mb-1">
                            <i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i>
                            This action is permanent and cannot be undone.
                        </p>
                        <p class="mb-0">
                            Deleting your account immediately: replaces your email address and display name
                            with an anonymous placeholder and permanently clears your password (you will
                            never be able to sign back in); signs you out of every device; deactivates every
                            API key; unlinks every SSO provider (Microsoft&nbsp;365, Google); and removes every
                            saved QR code from your library. Subscription and payment records are kept
                            (detached from your identity) for the legally-required financial/audit retention
                            period - see <a href="/privacy#retention">Section&nbsp;7 of our Privacy Policy</a>.
                        </p>
                    </div>

                    <form method="POST" action="/account/#data-section" id="erase-account-form">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="erase_account_submit" value="1">

                        <div class="mb-3">
                            <label for="erase-current-password" class="form-label">Current password</label>
                            <input type="password" class="form-control" id="erase-current-password"
                                   name="erase_current_password" autocomplete="current-password" required maxlength="256">
                        </div>

                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="erase-acknowledge"
                                   name="erase_acknowledge" value="1" required>
                            <label class="form-check-label" for="erase-acknowledge">
                                I understand this action is irreversible and permanently anonymises my account.
                            </label>
                        </div>

                        <div class="mb-3">
                            <label for="erase-confirm-phrase" class="form-label">
                                Type <strong>DELETE</strong> to confirm
                            </label>
                            <input type="text" class="form-control" id="erase-confirm-phrase"
                                   name="erase_confirm_phrase" required pattern="DELETE"
                                   autocomplete="off" aria-describedby="erase-confirm-phrase-help">
                            <div class="form-text" id="erase-confirm-phrase-help">
                                Type the word DELETE in capital letters exactly as shown.
                            </div>
                        </div>

                        <button type="submit" class="btn btn-danger" id="erase-account-btn">
                            <i class="fa-solid fa-user-slash me-1" aria-hidden="true"></i> Permanently delete my account
                        </button>
                    </form>
                </div>
            </div>

            <?php /* ================================================================
                 🚪 Logout
                 ================================================================ */ ?>
            <form method="POST" action="/account/">
                <?php echo csrfField(); ?>
                <input type="hidden" name="logout_submit" value="1">
                <button type="submit" class="btn btn-outline-secondary" id="logout-btn">
                    <i class="fa-solid fa-right-from-bracket me-1" aria-hidden="true"></i> Sign out
                </button>
            </form>
        </div>
    </section>

    <script>
        <?php /* 📊 Config for cuercode-account-dashboard.js */ ?>
        var CueRCodeAccountConfig = {
            apiBase: '/api/v1',
            savedCodesLimit: <?php echo json_encode($savedCodesLimit); ?>,
            savedCodesCount: <?php echo json_encode($savedCodesCount); ?>,
            apiKeysLimit: <?php echo json_encode($apiKeysLimit); ?>,
            apiKeysCount: <?php echo json_encode($apiKeysActiveCount); ?>,
            apiKeyScopeAllowlist: <?php echo json_encode(CUERCODE_API_KEY_SCOPE_ALLOWLIST); ?>,
            upgradeUrl: <?php echo json_encode($upgradeUrl); ?>
        };
    </script>

<?php
require_once CUERCODE_INCLUDES_DIR . 'footer.php';
