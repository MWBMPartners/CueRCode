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
$logoutRequested = false;

/**
 * 🛡️ Validate the CSRF token from the current $_POST body.
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

    if (function_exists('validateCSRFToken')) {
        return validateCSRFToken($token);
    }

    return true;
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
// ⚙️ Page Variables
// ==============================================================================

$pageTitle       = 'My Account';
$pageDescription = 'Manage your CueRCode profile, saved QR codes, and account security.';
$bodyClass       = 'page-account page-account-dashboard';
$pageCSS         = [];
$pageJS          = ['/assets/js/cuercode-account-dashboard.js'];

// ==============================================================================
// 📄 Render Page
// ==============================================================================

require_once CUERCODE_INCLUDES_DIR . 'header.php';
?>

    <!-- ====================================================================
         👤 Account Dashboard
         ==================================================================== -->
    <section class="py-4">
        <div class="container">
            <h1 class="h3 fw-bold mb-4">
                <i class="fa-solid fa-user me-2" aria-hidden="true"></i>
                My account
            </h1>

            <!-- 📑 On-page section navigation (mirrors the accessibility guide's convention) -->
            <nav aria-label="Account sections" class="mb-4">
                <ul class="nav nav-pills">
                    <li class="nav-item"><a class="nav-link" href="#profile-section">Profile</a></li>
                    <li class="nav-item"><a class="nav-link" href="#library-section">Saved codes</a></li>
                    <li class="nav-item"><a class="nav-link" href="#security-section">Security</a></li>
                </ul>
            </nav>

            <!-- ================================================================
                 📝 Profile Section
                 ================================================================ -->
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

            <!-- ================================================================
                 📚 Saved Codes Library Section
                 ================================================================ -->
            <div class="card shadow-sm mb-4" id="library-section">
                <div class="card-header bg-transparent d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h2 class="h5 mb-0"><i class="fa-solid fa-layer-group me-2" aria-hidden="true"></i>Saved codes</h2>
                    <span class="badge <?php echo $atSavedCodesCap ? 'text-bg-warning' : 'text-bg-secondary'; ?>" id="saved-codes-usage-badge">
                        <?php
                        echo htmlspecialchars((string) $savedCodesCount, ENT_QUOTES, 'UTF-8');
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
                    <!-- ♿ Upgrade prompt: visible/announced, never silently hidden -->
                    <div class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap gap-2" role="alert">
                        <span>
                            <i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i>
                            You have reached your plan's saved-codes limit.
                        </span>
                        <a href="<?php echo htmlspecialchars($upgradeUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-warning">
                            Upgrade plan
                        </a>
                    </div>
                    <?php } ?>

                    <?php if (empty($savedCodes)) { ?>
                    <p class="text-body-secondary mb-0">
                        You haven't saved any QR codes yet. Generate one on the
                        <a href="/">main page</a> and use the "Save to my account" button.
                    </p>
                    <?php } else { ?>
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

            <!-- ================================================================
                 🔐 Security / Sessions Section
                 ================================================================ -->
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

            <!-- ================================================================
                 🚪 Logout
                 ================================================================ -->
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
        /* 📊 Config for cuercode-account-dashboard.js */
        var CueRCodeAccountConfig = {
            apiBase: '/api/v1',
            savedCodesLimit: <?php echo json_encode($savedCodesLimit); ?>,
            savedCodesCount: <?php echo json_encode($savedCodesCount); ?>,
            upgradeUrl: <?php echo json_encode($upgradeUrl); ?>
        };
    </script>

<?php
require_once CUERCODE_INCLUDES_DIR . 'footer.php';
