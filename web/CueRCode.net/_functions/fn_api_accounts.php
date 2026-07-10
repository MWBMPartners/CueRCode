<?php
/**
 * ==============================================================================
 * 🔐 CueRCode - API Account Handlers (Epic E3 stage 1, #11/#12/#14/#15)
 * ==============================================================================
 *
 * Path: _functions/fn_api_accounts.php
 *
 * HTTP handler layer for the account/authentication REST endpoints. These
 * functions adapt the framework-agnostic auth engine (fn_auth.php) to the
 * router's request/response conventions: reading the parsed JSON request
 * body, validating field presence/shape/length, enforcing CSRF-or-API-key
 * protection, and emitting jsonResponse()/errorResponse()/successResponse()
 * calls with the correct HTTP status codes.
 *
 * Endpoints handled:
 *   - POST /api/v1/auth/register              -> handleAuthRegister()
 *   - POST /api/v1/auth/login                  -> handleAuthLogin()
 *   - POST /api/v1/auth/logout                 -> handleAuthLogout()
 *   - POST /api/v1/auth/verify-email           -> handleAuthVerifyEmail()
 *   - POST /api/v1/auth/request-password-reset -> handleAuthRequestPasswordReset()
 *   - POST /api/v1/auth/reset-password         -> handleAuthResetPassword()
 *
 * CSRF / API-key model: the router's generic 'auth' route flag (which gates
 * on EITHER a valid CSRF token OR a valid API key, per fn_api_auth.php) is
 * intentionally NOT used for these six routes (they are registered with
 * 'auth' => false so unauthenticated visitors can reach register/login/
 * reset-request). Instead, requireCsrfOrApiKey() below performs the SAME
 * check explicitly inside each handler, but replies with 403 (not the
 * router's 401) on failure - the semantically correct code for a rejected
 * CSRF token, matching fn_csrf.php's own documented convention.
 *
 * @package     CueRCode
 * @subpackage  API
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2 (Epic E3 stage 1)
 * @see         fn_auth.php      - the auth engine these handlers call into
 * @see         fn_password.php  - validatePasswordPolicy()
 * @see         fn_validation.php - validateEmail()
 * @see         fn_api_auth.php  - validateAPIKey(), getClientIP()
 * @see         fn_csrf.php      - validateCSRFToken()
 * @see         https://www.php.net/manual/en/function.json-decode.php
 */

// ==============================================================================
// 🚫 Direct access protection
// ==============================================================================
if (isset($_SERVER['SCRIPT_FILENAME'])) {
    $scriptFilename = $_SERVER['SCRIPT_FILENAME'];
} else {
    $scriptFilename = '';
}
if (basename($scriptFilename) === basename(__FILE__)) {
    header('HTTP/1.1 403 Forbidden');
    exit('Access denied.');
}

// ==============================================================================
// 📏 Field length caps (mirrors the F-007 convention in fn_api_generate.php)
// ==============================================================================

if (!defined('CUERCODE_ACCOUNTS_MAX_EMAIL_LEN')) {
    define('CUERCODE_ACCOUNTS_MAX_EMAIL_LEN', 254); // RFC 5321 path length limit
}

if (!defined('CUERCODE_ACCOUNTS_MAX_PASSWORD_LEN')) {
    define('CUERCODE_ACCOUNTS_MAX_PASSWORD_LEN', 256); // matches CUERCODE_PASSWORD_MAX_LENGTH
}

if (!defined('CUERCODE_ACCOUNTS_MAX_DISPLAY_NAME_LEN')) {
    define('CUERCODE_ACCOUNTS_MAX_DISPLAY_NAME_LEN', 100); // matches tblUsers.displayName column width
}

if (!defined('CUERCODE_ACCOUNTS_MAX_TOKEN_LEN')) {
    define('CUERCODE_ACCOUNTS_MAX_TOKEN_LEN', 128); // generated tokens are 64 hex chars - generous headroom
}

// ==============================================================================
// 🛡️ requireCsrfOrApiKey() - Shared CSRF/API-key guard for all six routes
// ==============================================================================

/**
 * 🛡️ Require either a valid CSRF token (web/session path) or a valid API key.
 *
 * Terminates the request with 403 Forbidden (error_code CSRF_INVALID) if
 * neither is present/valid. Deliberately independent of the router's generic
 * authenticateAPIRequest()/'auth' route flag (which replies 401 for every
 * failure mode) so these account-management routes can use the more
 * semantically correct 403 for a rejected CSRF token, while still reusing
 * the EXACT SAME validateCSRFToken()/validateAPIKey() primitives.
 *
 * @return void (terminates via errorResponse() on failure)
 *
 * @see fn_csrf.php     - validateCSRFToken()
 * @see fn_api_auth.php - validateAPIKey()
 */
function requireCsrfOrApiKey(): void
{
    if (isset($_SERVER['HTTP_X_API_KEY'])) {
        $apiKey = $_SERVER['HTTP_X_API_KEY'];
    } else {
        $apiKey = '';
    }

    if ($apiKey !== '' && function_exists('validateAPIKey')) {
        $keyValidation = validateAPIKey($apiKey);
        if ($keyValidation['valid']) {
            return;
        }
        errorResponse('Invalid or inactive API key.', 403, 'CSRF_INVALID');
    }

    if (isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'];
    } else {
        $csrfToken = '';
    }

    if ($csrfToken === '' && isset($_POST['csrf_token'])) {
        $csrfToken = $_POST['csrf_token'];
    }

    if ($csrfToken !== '' && function_exists('validateCSRFToken') && validateCSRFToken($csrfToken)) {
        return;
    }

    errorResponse(
        'A valid CSRF token (X-CSRF-Token header) or API key (X-API-Key header) is required.',
        403,
        'CSRF_INVALID'
    );
}

// ==============================================================================
// 📥 requestString() - Safely extract a trimmed string field from the JSON body
// ==============================================================================

/**
 * 📥 Extract a string field from the parsed JSON request body.
 *
 * @param array  $requestBody The parsed JSON request body
 * @param string $field       The field name to extract
 *
 * @return string|null The trimmed string value, or null if absent/not a string
 */
function requestString(array $requestBody, string $field): ?string
{
    if (!isset($requestBody[$field]) || !is_string($requestBody[$field])) {
        return null;
    }

    return trim($requestBody[$field]);
}

// ==============================================================================
// 📝 handleAuthRegister() - POST /api/v1/auth/register
// ==============================================================================

/**
 * 📝 Handle POST /api/v1/auth/register
 *
 * @param array $requestBody The parsed JSON request body
 *
 * @return void (terminates via jsonResponse()/errorResponse())
 *
 * @see registerUser()
 */
function handleAuthRegister(array $requestBody): void
{
    requireCsrfOrApiKey();

    $email    = requestString($requestBody, 'email');
    $password = requestString($requestBody, 'password');
    $displayName = requestString($requestBody, 'display_name');

    if ($email === null || $email === '' || $password === null || $password === '') {
        errorResponse('Both "email" and "password" fields are required.', 400, 'INVALID_REQUEST');
    }

    if (mb_strlen($email) > CUERCODE_ACCOUNTS_MAX_EMAIL_LEN) {
        errorResponse('Field "email" is too long. Maximum ' . CUERCODE_ACCOUNTS_MAX_EMAIL_LEN . ' characters.', 400, 'INVALID_REQUEST');
    }

    if (mb_strlen($password) > CUERCODE_ACCOUNTS_MAX_PASSWORD_LEN) {
        errorResponse('Field "password" is too long. Maximum ' . CUERCODE_ACCOUNTS_MAX_PASSWORD_LEN . ' characters.', 400, 'INVALID_REQUEST');
    }

    if ($displayName !== null && mb_strlen($displayName) > CUERCODE_ACCOUNTS_MAX_DISPLAY_NAME_LEN) {
        errorResponse('Field "display_name" is too long. Maximum ' . CUERCODE_ACCOUNTS_MAX_DISPLAY_NAME_LEN . ' characters.', 400, 'INVALID_REQUEST');
    }

    if (!function_exists('validateEmail') || !validateEmail($email)) {
        errorResponse('Field "email" is not a valid email address.', 422, 'INVALID_EMAIL');
    }

    $minLength = 8;
    if (function_exists('getConfig')) {
        $minLength = (int) getConfig('password_min_length', 8);
    }

    $policy = validatePasswordPolicy($password, $minLength);

    if (!$policy['valid']) {
        errorResponse((string) $policy['error'], 422, 'WEAK_PASSWORD');
    }

    $result = registerUser($email, $password, $displayName);

    successResponse($result['data'], $result['message'], $result['httpCode']);
}

// ==============================================================================
// 🚪 handleAuthLogin() - POST /api/v1/auth/login
// ==============================================================================

/**
 * 🚪 Handle POST /api/v1/auth/login
 *
 * @param array $requestBody The parsed JSON request body
 *
 * @return void (terminates via jsonResponse()/errorResponse())
 *
 * @see loginUser()
 */
function handleAuthLogin(array $requestBody): void
{
    requireCsrfOrApiKey();

    $email    = requestString($requestBody, 'email');
    $password = requestString($requestBody, 'password');

    if ($email === null || $email === '' || $password === null || $password === '') {
        errorResponse('Both "email" and "password" fields are required.', 400, 'INVALID_REQUEST');
    }

    if (mb_strlen($email) > CUERCODE_ACCOUNTS_MAX_EMAIL_LEN || mb_strlen($password) > CUERCODE_ACCOUNTS_MAX_PASSWORD_LEN) {
        errorResponse('Invalid email or password, or the account is not yet verified.', 401, 'INVALID_CREDENTIALS');
    }

    if (function_exists('getClientIP')) {
        $clientIP = getClientIP();
    } elseif (isset($_SERVER['REMOTE_ADDR'])) {
        $clientIP = $_SERVER['REMOTE_ADDR'];
    } else {
        $clientIP = 'unknown';
    }

    if (isset($_SERVER['HTTP_USER_AGENT'])) {
        $userAgent = $_SERVER['HTTP_USER_AGENT'];
    } else {
        $userAgent = '';
    }

    $result = loginUser($email, $password, $clientIP, $userAgent);

    if (!$result['success']) {
        errorResponse($result['message'], $result['httpCode'], (string) $result['errorCode']);
    }

    successResponse($result['data'], $result['message'], $result['httpCode']);
}

// ==============================================================================
// 🚪 handleAuthLogout() - POST /api/v1/auth/logout
// ==============================================================================

/**
 * 🚪 Handle POST /api/v1/auth/logout
 *
 * @return void (terminates via jsonResponse())
 *
 * @see logoutCurrentUser()
 */
function handleAuthLogout(): void
{
    requireCsrfOrApiKey();

    $result = logoutCurrentUser();

    successResponse($result['data'], $result['message'], $result['httpCode']);
}

// ==============================================================================
// ✅ handleAuthVerifyEmail() - POST /api/v1/auth/verify-email
// ==============================================================================

/**
 * ✅ Handle POST /api/v1/auth/verify-email
 *
 * @param array $requestBody The parsed JSON request body
 *
 * @return void (terminates via jsonResponse()/errorResponse())
 *
 * @see verifyEmailToken()
 */
function handleAuthVerifyEmail(array $requestBody): void
{
    requireCsrfOrApiKey();

    $token = requestString($requestBody, 'token');

    if ($token === null || $token === '') {
        errorResponse('Field "token" is required.', 400, 'INVALID_REQUEST');
    }

    if (mb_strlen($token) > CUERCODE_ACCOUNTS_MAX_TOKEN_LEN) {
        errorResponse('This verification link is invalid or has expired.', 400, 'VERIFICATION_TOKEN_INVALID');
    }

    $result = verifyEmailToken($token);

    if (!$result['success']) {
        errorResponse($result['message'], $result['httpCode'], (string) $result['errorCode']);
    }

    successResponse($result['data'], $result['message'], $result['httpCode']);
}

// ==============================================================================
// 🔑 handleAuthRequestPasswordReset() - POST /api/v1/auth/request-password-reset
// ==============================================================================

/**
 * 🔑 Handle POST /api/v1/auth/request-password-reset
 *
 * @param array $requestBody The parsed JSON request body
 *
 * @return void (terminates via jsonResponse()/errorResponse())
 *
 * @see requestPasswordReset()
 */
function handleAuthRequestPasswordReset(array $requestBody): void
{
    requireCsrfOrApiKey();

    $email = requestString($requestBody, 'email');

    if ($email === null || $email === '') {
        errorResponse('Field "email" is required.', 400, 'INVALID_REQUEST');
    }

    if (mb_strlen($email) > CUERCODE_ACCOUNTS_MAX_EMAIL_LEN) {
        errorResponse('Field "email" is too long. Maximum ' . CUERCODE_ACCOUNTS_MAX_EMAIL_LEN . ' characters.', 400, 'INVALID_REQUEST');
    }

    if (!function_exists('validateEmail') || !validateEmail($email)) {
        errorResponse('Field "email" is not a valid email address.', 422, 'INVALID_EMAIL');
    }

    if (function_exists('getClientIP')) {
        $clientIP = getClientIP();
    } elseif (isset($_SERVER['REMOTE_ADDR'])) {
        $clientIP = $_SERVER['REMOTE_ADDR'];
    } else {
        $clientIP = 'unknown';
    }

    $result = requestPasswordReset($email, $clientIP);

    successResponse($result['data'], $result['message'], $result['httpCode']);
}

// ==============================================================================
// 🔁 handleAuthResetPassword() - POST /api/v1/auth/reset-password
// ==============================================================================

/**
 * 🔁 Handle POST /api/v1/auth/reset-password
 *
 * @param array $requestBody The parsed JSON request body
 *
 * @return void (terminates via jsonResponse()/errorResponse())
 *
 * @see resetPasswordWithToken()
 */
function handleAuthResetPassword(array $requestBody): void
{
    requireCsrfOrApiKey();

    $token       = requestString($requestBody, 'token');
    $newPassword = requestString($requestBody, 'new_password');

    if ($token === null || $token === '' || $newPassword === null || $newPassword === '') {
        errorResponse('Both "token" and "new_password" fields are required.', 400, 'INVALID_REQUEST');
    }

    if (mb_strlen($token) > CUERCODE_ACCOUNTS_MAX_TOKEN_LEN) {
        errorResponse('This password reset link is invalid or has expired.', 400, 'RESET_TOKEN_INVALID');
    }

    if (mb_strlen($newPassword) > CUERCODE_ACCOUNTS_MAX_PASSWORD_LEN) {
        errorResponse('Field "new_password" is too long. Maximum ' . CUERCODE_ACCOUNTS_MAX_PASSWORD_LEN . ' characters.', 400, 'INVALID_REQUEST');
    }

    $result = resetPasswordWithToken($token, $newPassword);

    if (!$result['success']) {
        errorResponse($result['message'], $result['httpCode'], (string) $result['errorCode']);
    }

    successResponse($result['data'], $result['message'], $result['httpCode']);
}
