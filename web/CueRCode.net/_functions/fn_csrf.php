<?php
/**
 * ==============================================================================
 * 🛡️ CueRCode - CSRF Protection
 * ==============================================================================
 *
 * Path: _functions/fn_csrf.php
 *
 * Provides Cross-Site Request Forgery (CSRF) protection using random tokens
 * stored in the PHP session. Every state-changing request (POST, PUT, DELETE)
 * must include a valid CSRF token to be processed.
 *
 * Usage in forms:
 *   <form method="POST" action="/settings">
 *       <?php echo csrfField(); ?>
 *       ...
 *   </form>
 *
 * Usage in AJAX:
 *   Add to <head>: <?php echo csrfMeta(); ?>
 *   Then in JavaScript, read the meta tag and include the token in
 *   the X-CSRF-Token header or as a POST parameter.
 *
 * Validation:
 *   if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
 *       errorResponse('Invalid CSRF token', 403, 'CSRF_INVALID');
 *   }
 *
 * Tokens are validated using hash_equals() to prevent timing attacks.
 *
 * @package     CueRCode
 * @subpackage  Security
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 1
 * @see         https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html
 * @see         https://www.php.net/manual/en/function.random-bytes.php
 * @see         https://www.php.net/manual/en/function.hash-equals.php
 * @see         https://www.php.net/manual/en/function.session-start.php
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
// 📌 CSRF Constants
// ==============================================================================

/**
 * @var string Session key used to store the CSRF token
 * Using a namespaced key prevents collisions with other session data.
 */
if (!defined('CUERCODE_CSRF_SESSION_KEY')) {
    define('CUERCODE_CSRF_SESSION_KEY', 'cuercode_csrf_token');
}

/**
 * @var string Session key used to store the token creation timestamp
 * Used for token expiration checks.
 */
if (!defined('CUERCODE_CSRF_TIMESTAMP_KEY')) {
    define('CUERCODE_CSRF_TIMESTAMP_KEY', 'cuercode_csrf_timestamp');
}

/**
 * @var string The name attribute for the hidden form input field
 * This is the POST parameter name that forms will submit.
 */
if (!defined('CUERCODE_CSRF_FIELD_NAME')) {
    define('CUERCODE_CSRF_FIELD_NAME', 'csrf_token');
}

/**
 * @var int Default token lifetime in seconds (1 hour)
 * Tokens older than this are considered expired and will be rejected.
 * This can be overridden by the 'csrf_token_lifetime' setting in tblSettings.
 */
if (!defined('CUERCODE_CSRF_DEFAULT_LIFETIME')) {
    define('CUERCODE_CSRF_DEFAULT_LIFETIME', 3600);
}

// ==============================================================================
// 🔑 generateCSRFToken() - Generate a CSRF token
// ==============================================================================

/**
 * 🔑 Generate a new CSRF token and store it in the session
 *
 * Creates a cryptographically secure random token using random_bytes()
 * and stores it in the PHP session along with a creation timestamp.
 *
 * If a session is not already active, this function will start one.
 *
 * The token is a 64-character hexadecimal string (32 random bytes).
 *
 * If a valid (non-expired) token already exists in the session, it is
 * returned rather than generating a new one. This prevents token
 * invalidation when multiple forms are rendered on the same page.
 *
 * @return string The 64-character hex CSRF token
 *
 * @see https://www.php.net/manual/en/function.random-bytes.php
 * @see https://www.php.net/manual/en/function.bin2hex.php
 * @see https://www.php.net/manual/en/function.session-start.php
 * @see https://www.php.net/manual/en/function.session-status.php
 *
 * @example
 *   $token = generateCSRFToken();
 *   // $token is something like: 'a1b2c3d4e5f6...' (64 hex characters)
 */
function generateCSRFToken(): string
{
    // 📌 Ensure a session is active
    // @see https://www.php.net/manual/en/function.session-status.php
    // @see https://www.php.net/manual/en/function.session-start.php
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // 🔍 Check if a valid (non-expired) token already exists in the session
    // If so, return the existing token to avoid invalidating tokens on
    // multi-form pages or rapid page reloads.
    if (isset($_SESSION[CUERCODE_CSRF_SESSION_KEY]) && isset($_SESSION[CUERCODE_CSRF_TIMESTAMP_KEY])) {
        $existingToken     = $_SESSION[CUERCODE_CSRF_SESSION_KEY];
        $tokenTimestamp    = (int) $_SESSION[CUERCODE_CSRF_TIMESTAMP_KEY];
        $tokenLifetime     = getCSRFTokenLifetime();
        $elapsed           = time() - $tokenTimestamp;

        // ✅ Return existing token if it hasn't expired
        if ($elapsed < $tokenLifetime && $existingToken !== '') {
            return $existingToken;
        }
    }

    // 🔑 Generate a new cryptographically secure token
    // random_bytes() produces cryptographically secure pseudo-random bytes.
    // bin2hex() converts to a 64-character hexadecimal string (32 bytes * 2 hex chars).
    // @see https://www.php.net/manual/en/function.random-bytes.php
    // @see https://www.php.net/manual/en/function.bin2hex.php
    $token = bin2hex(random_bytes(32));

    // 💾 Store the token and timestamp in the session
    $_SESSION[CUERCODE_CSRF_SESSION_KEY]   = $token;
    $_SESSION[CUERCODE_CSRF_TIMESTAMP_KEY] = time();

    return $token;
}

// ==============================================================================
// ✅ validateCSRFToken() - Validate a submitted CSRF token
// ==============================================================================

/**
 * ✅ Validate a CSRF token submitted with a request
 *
 * Compares the submitted token against the token stored in the session
 * using hash_equals() to prevent timing attacks. Also checks that the
 * token has not expired based on the configured lifetime.
 *
 * After successful validation, the token remains valid for subsequent
 * requests within its lifetime (it is NOT consumed/rotated). Token
 * rotation happens naturally when the lifetime expires.
 *
 * @param string $token The CSRF token submitted with the request
 *                      (typically from $_POST['csrf_token'] or the X-CSRF-Token header)
 *
 * @return bool True if the token is valid and not expired, false otherwise
 *
 * @see https://www.php.net/manual/en/function.hash-equals.php
 * @see https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html
 *
 * @example
 *   // Validate from POST data
 *   if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
 *       errorResponse('Invalid or expired CSRF token.', 403, 'CSRF_INVALID');
 *   }
 *
 *   // Validate from AJAX header
 *   $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
 *   if (!validateCSRFToken($headerToken)) {
 *       errorResponse('CSRF validation failed.', 403, 'CSRF_INVALID');
 *   }
 */
function validateCSRFToken(string $token): bool
{
    // 📌 Ensure a session is active
    // @see https://www.php.net/manual/en/function.session-status.php
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // ❌ Check that a token exists in the session
    if (!isset($_SESSION[CUERCODE_CSRF_SESSION_KEY]) || !isset($_SESSION[CUERCODE_CSRF_TIMESTAMP_KEY])) {
        return false;
    }

    $sessionToken   = $_SESSION[CUERCODE_CSRF_SESSION_KEY];
    $tokenTimestamp  = (int) $_SESSION[CUERCODE_CSRF_TIMESTAMP_KEY];

    // ❌ Reject empty tokens
    if ($token === '' || $sessionToken === '') {
        return false;
    }

    // ⏱️ Check token expiration
    // @see https://www.php.net/manual/en/function.time.php
    $tokenLifetime = getCSRFTokenLifetime();
    $elapsed       = time() - $tokenTimestamp;

    if ($elapsed > $tokenLifetime) {
        // 🗑️ Token has expired - clear it from the session
        unset($_SESSION[CUERCODE_CSRF_SESSION_KEY]);
        unset($_SESSION[CUERCODE_CSRF_TIMESTAMP_KEY]);
        return false;
    }

    // ✅ Compare tokens using timing-safe comparison
    // hash_equals() prevents timing attacks by ensuring the comparison
    // takes the same amount of time regardless of where the strings differ.
    // @see https://www.php.net/manual/en/function.hash-equals.php
    return hash_equals($sessionToken, $token);
}

// ==============================================================================
// 📋 csrfField() - Generate an HTML hidden input element
// ==============================================================================

/**
 * 📋 Generate an HTML hidden input element containing the CSRF token
 *
 * Returns a self-closing <input type="hidden"> element ready for inclusion
 * in HTML forms. The element name matches CUERCODE_CSRF_FIELD_NAME constant
 * ('csrf_token') and the value is the current CSRF token.
 *
 * @return string HTML hidden input element string
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/HTML/Element/input/hidden
 * @see https://www.php.net/manual/en/function.htmlspecialchars.php
 *
 * @example
 *   <form method="POST" action="/api/settings">
 *       <?php echo csrfField(); ?>
 *       <input type="text" name="site_name" value="">
 *       <button type="submit">Save</button>
 *   </form>
 *
 *   // Output:
 *   // <input type="hidden" name="csrf_token" value="a1b2c3d4...">
 */
function csrfField(): string
{
    // 🔑 Generate or retrieve the current CSRF token
    $token = generateCSRFToken();

    // 📌 Build the hidden input element
    // The value is HTML-encoded to prevent XSS if the token somehow
    // contained special characters (it won't, since it's hex, but
    // defence-in-depth is good practice).
    // @see https://www.php.net/manual/en/function.htmlspecialchars.php
    return '<input type="hidden" '
        . 'name="' . htmlspecialchars(CUERCODE_CSRF_FIELD_NAME, ENT_QUOTES, 'UTF-8') . '" '
        . 'value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

// ==============================================================================
// 🏷️ csrfMeta() - Generate an HTML meta tag for AJAX usage
// ==============================================================================

/**
 * 🏷️ Generate an HTML meta tag containing the CSRF token for AJAX usage
 *
 * Returns a <meta> tag that JavaScript can read to include the CSRF token
 * in AJAX request headers (typically as X-CSRF-Token).
 *
 * Place this in the <head> section of your HTML:
 *   <?php echo csrfMeta(); ?>
 *
 * Then in JavaScript:
 *   const token = document.querySelector('meta[name="csrf-token"]').content;
 *   fetch('/api/endpoint', {
 *       method: 'POST',
 *       headers: { 'X-CSRF-Token': token }
 *   });
 *
 * @return string HTML meta tag string
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/HTML/Element/meta
 *
 * @example
 *   // In PHP (inside <head>):
 *   <?php echo csrfMeta(); ?>
 *
 *   // Output:
 *   // <meta name="csrf-token" content="a1b2c3d4...">
 *
 *   // In JavaScript:
 *   const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
 *   fetch('/api/v1/generate', {
 *       method: 'POST',
 *       headers: {
 *           'Content-Type': 'application/json',
 *           'X-CSRF-Token': csrfToken
 *       },
 *       body: JSON.stringify({ url: 'https://example.com' })
 *   });
 */
function csrfMeta(): string
{
    // 🔑 Generate or retrieve the current CSRF token
    $token = generateCSRFToken();

    // 📌 Build the meta tag
    // @see https://www.php.net/manual/en/function.htmlspecialchars.php
    return '<meta name="csrf-token" '
        . 'content="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

// ==============================================================================
// ⏱️ getCSRFTokenLifetime() - Get the configured token lifetime
// ==============================================================================

/**
 * ⏱️ Get the configured CSRF token lifetime in seconds
 *
 * Checks the 'csrf_token_lifetime' setting in tblSettings via getConfig().
 * Falls back to the CUERCODE_CSRF_DEFAULT_LIFETIME constant (3600 seconds = 1 hour)
 * if the setting is not available.
 *
 * @return int Token lifetime in seconds
 *
 * @see fn_config.php - getConfig()
 */
function getCSRFTokenLifetime(): int
{
    // 📌 Attempt to read from database settings
    if (function_exists('getConfig')) {
        $lifetime = getConfig('csrf_token_lifetime', CUERCODE_CSRF_DEFAULT_LIFETIME);
        return max(60, (int) $lifetime);  // Minimum 60 seconds
    }

    return CUERCODE_CSRF_DEFAULT_LIFETIME;
}
