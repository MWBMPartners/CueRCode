<?php
/**
 * ==============================================================================
 * 📤 CueRCode - Own-Account Data Export Download (#37, epic #52)
 * ==============================================================================
 *
 * Path: public_html/account/export/index.php
 *
 * Streams a complete JSON export of the CURRENTLY AUTHENTICATED SESSION'S OWN
 * data as a downloadable attachment. This is deliberately NOT part of the JSON
 * API router (public_html/api/v1/) - it is a plain GET-only resource meant to
 * be used directly as an <a href> ("Download my data" link/button on
 * public_html/account/index.php), which cannot attach the X-CSRF-Token header
 * the JSON API's account/* routes require for their session-only auth method.
 * Exporting one's OWN data has no state-changing side effect (mirrors
 * account/avatar/index.php's identical CSRF rationale - see that file's own
 * docblock), so the PHP session cookie (HttpOnly, SameSite=Lax) alone is the
 * correct and sufficient gate here. `GET /api/v1/account/export` (the JSON
 * API sibling in fn_api_account.php) remains the CSRF/session-header-gated
 * route for programmatic/AJAX callers - both call the exact SAME engine
 * function, collectAccountExportData(), so there is only ONE code path that
 * decides what data is included.
 *
 * OWN-SCOPED SECURITY POSTURE:
 *   - The target user is ALWAYS resolved from currentUser() (the caller's own
 *     session) - there is no userID/uuid query parameter accepted here that
 *     could select another user's data. This endpoint can NEVER disclose
 *     another user's export.
 *   - Not logged in redirects to /login/?next=/account/export/ (the SAME
 *     no-anonymous-disclosure convention as public_html/account/index.php
 *     itself) rather than a bare 401 - this is a browser-navigable page, not
 *     a JSON API endpoint.
 *
 * @package     CueRCode
 * @subpackage  Pages / Account
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2 (#37, epic #52)
 * @see         _functions/fn_auth.php        - currentUser()
 * @see         _functions/fn_api_account.php - collectAccountExportData(), handleAccountDataExport()
 * @see         public_html/account/avatar/index.php - the sibling pattern this mirrors
 */

// ==============================================================================
// 🚀 Load Application Bootstrap
// ==============================================================================
require_once __DIR__ . '/../../../_includes/bootstrap.php';

// ==============================================================================
// 📦 Load the export engine function (not loaded by bootstrap.php by default -
// only the API router's own file list loads fn_api_account.php normally).
// ==============================================================================
$exportFunctionFile = CUERCODE_FUNCTIONS_DIR . 'fn_api_account.php';
if (file_exists($exportFunctionFile)) {
    require_once $exportFunctionFile;
}

// ==============================================================================
// 👤 Resolve the caller's OWN session - redirect (never a bare disclosure) if absent
// ==============================================================================

$currentUserData = function_exists('currentUser') ? currentUser() : null;

if ($currentUserData === null) {
    header('Location: /login/?next=' . rawurlencode('/account/export/'));
    exit;
}

$currentUserId = $currentUserData['userID'];

if (!function_exists('collectAccountExportData')) {
    // 📌 Defensive fail-safe - should never happen in normal operation since
    // fn_api_account.php was just required above.
    header('HTTP/1.1 503 Service Unavailable');
    exit('The data-export service is temporarily unavailable.');
}

$exportData = collectAccountExportData($currentUserId);

if (function_exists('logActivity')) {
    // 📌 PII-free by design: only the userID (entityID) is logged, never the
    // email/display name/payloads that were just exported.
    logActivity('account_data_exported', 'User exported their own account data (direct download)', 'user', $currentUserId, []);
}

// ==============================================================================
// 📥 Stream the JSON file as a download attachment
// ==============================================================================

$jsonOutput = json_encode(
    $exportData,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
);

if ($jsonOutput === false) {
    header('HTTP/1.1 500 Internal Server Error');
    exit('Your data export could not be generated. Please try again later.');
}

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="cuercode-data-export.json"');
    header('Content-Length: ' . strlen($jsonOutput));
    // 📌 This is one specific user's own data - never cached, never shared.
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
}

echo $jsonOutput;
exit;
