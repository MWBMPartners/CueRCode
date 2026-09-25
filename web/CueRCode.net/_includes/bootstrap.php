<?php
/**
 * ==============================================================================
 * 🚀 CueRCode - Master Bootstrap / Initialisation File
 * ==============================================================================
 *
 * Path: _includes/bootstrap.php
 *
 * This file is the single entry point for initialising the CueRCode application.
 * It loads all required components in the correct order: autoloader, database,
 * functions, error handlers, and configuration.
 *
 * Every PHP entry point (index.php, API router, etc.) should include this file
 * first via: require_once __DIR__ . '/../_includes/bootstrap.php';
 *
 * @package     CueRCode
 * @subpackage  Core
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 1
 * @see         https://www.php.net/manual/en/function.require-once.php
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
// ⏱️ Record request start time for performance monitoring
// ==============================================================================
if (!defined('CUERCODE_REQUEST_START')) {
    if (isset($_SERVER['REQUEST_TIME_FLOAT'])) {
        $requestStart = $_SERVER['REQUEST_TIME_FLOAT'];
    } else {
        $requestStart = microtime(true);
    }
    define('CUERCODE_REQUEST_START', $requestStart);
}

// ==============================================================================
// 📂 Define Path Constants
// ==============================================================================
// All paths use DIRECTORY_SEPARATOR for platform neutrality.
// @see https://www.php.net/manual/en/language.constants.predefined.php
// ==============================================================================

/** @var string Directory separator shorthand */
$ds = DIRECTORY_SEPARATOR;

/**
 * @var string CueRCode site root directory
 * This file is at: CueRCode/_includes/bootstrap.php
 * Site root is:     CueRCode/
 */
if (!defined('CUERCODE_SITE_ROOT')) {
    define('CUERCODE_SITE_ROOT', dirname(__DIR__) . $ds);
}

/**
 * @var string Server-wide root directory (parent of CueRCode/)
 */
if (!defined('CUERCODE_SERVER_ROOT')) {
    define('CUERCODE_SERVER_ROOT', dirname(__DIR__, 2) . $ds);
}

/**
 * @var string Public HTML root directory
 */
if (!defined('CUERCODE_PUBLIC_ROOT')) {
    define('CUERCODE_PUBLIC_ROOT', CUERCODE_SITE_ROOT . 'public_html' . $ds);
}

/**
 * @var string Site-specific includes directory
 */
if (!defined('CUERCODE_INCLUDES_DIR')) {
    define('CUERCODE_INCLUDES_DIR', CUERCODE_SITE_ROOT . '_includes' . $ds);
}

/**
 * @var string Site-specific functions directory
 */
if (!defined('CUERCODE_FUNCTIONS_DIR')) {
    define('CUERCODE_FUNCTIONS_DIR', CUERCODE_SITE_ROOT . '_functions' . $ds);
}

/**
 * @var string Site-specific libraries directory
 */
if (!defined('CUERCODE_LIBRARIES_DIR')) {
    define('CUERCODE_LIBRARIES_DIR', CUERCODE_SITE_ROOT . '_libraries' . $ds);
}

/**
 * @var string Site-specific uploads directory
 */
if (!defined('CUERCODE_UPLOADS_DIR')) {
    define('CUERCODE_UPLOADS_DIR', CUERCODE_SITE_ROOT . '_uploads' . $ds);
}

// ==============================================================================
// 📦 Step 1: Load Third-Party Library Autoloader
// ==============================================================================
// The custom PSR-4 autoloader replaces Composer's vendor/autoload.php.
// This must be loaded FIRST so that library classes are available to all functions.
// @see https://www.php-fig.org/psr/psr-4/
// ==============================================================================

$autoloaderPath = CUERCODE_LIBRARIES_DIR . 'autoload.php';

if (file_exists($autoloaderPath)) {
    require_once $autoloaderPath;
} else {
    // ⚠️ Autoloader not found - log warning but continue
    // (the application can still function for basic operations without QR libraries)
    error_log(
        'CueRCode WARNING: Library autoloader not found at: ' . $autoloaderPath
        . '. QR code generation will not be available.'
    );
}

// ==============================================================================
// 🗄️ Step 2: Load Database Connection
// ==============================================================================
// Provides getDB() and dbQuery() functions for all database operations.
// @see https://www.php.net/manual/en/book.mysqli.php
// ==============================================================================

$dbConnectPath = CUERCODE_INCLUDES_DIR . 'db_connect.php';

if (file_exists($dbConnectPath)) {
    require_once $dbConnectPath;
} else {
    error_log('CueRCode CRITICAL: Database connection file not found at: ' . $dbConnectPath);
    // Cannot proceed without database connection capabilities
    if (php_sapi_name() !== 'cli') {
        header('HTTP/1.1 503 Service Unavailable');
        echo '<h1>Service Unavailable</h1><p>Database configuration is missing.</p>';
    }
    exit(1);
}

// ==============================================================================
// ⚙️ Step 3: Load Function Files
// ==============================================================================
// Functions are loaded in dependency order:
// 0. Log masking (no dependencies - #126)
// 1. Encryption (needed by config)
// 2. Config (needed by most other functions)
// 3. Error handler (needs config for debug mode check)
// 4. Activity log, debug, validation, response, CSRF (independent)
// @see https://www.php.net/manual/en/function.require-once.php
// ==============================================================================

// 📋 Function files to load (in dependency order)
$functionFiles = [
    // 🙈 #126: hides email addresses in text before it is written to PHP's
    //    error log. It has no dependencies, so it loads first. fn_error_handler.php,
    //    fn_activity_log.php and fn_email.php ALSO require_once it themselves,
    //    because each can be loaded without this file (the cron runner loads
    //    fn_email.php before bootstrap; the tests load single files). require_once
    //    resolves the real path, so the repeat loads do nothing.
    'fn_log_mask.php',
    'fn_encryption.php',     // 🔐 Encryption utilities (no dependencies)
    'fn_config.php',         // ⚙️ Configuration manager (depends on encryption)
    'fn_error_handler.php',  // 🚨 Error/exception handling (depends on config for debug mode)
    'fn_activity_log.php',   // 📊 Activity logging
    'fn_debug.php',          // 🐛 Debug mode utilities
    'fn_validation.php',     // ✅ Input validation
    'fn_response.php',       // 📤 Response formatting
    'fn_csrf.php',           // 🛡️ CSRF protection
    // 🔐 Epic E3 stage 2b (#52/#12/#14/#23/#24): the auth engine is promoted
    //    from "API-router-only" to a GLOBAL bootstrap include so that EVERY
    //    page - not just the JSON API - can call currentUser()/requireAuth()
    //    for the site-wide navbar user indicator (header.php, §11.4) without
    //    each page having to remember its own require_once boilerplate. The
    //    API router's own $apiFunctionFiles list still requires these three
    //    explicitly too; require_once de-duplicates by resolved path, so that
    //    is a harmless no-op, not a double-include.
    'fn_password.php',      // 🔑 Argon2id hashing (fn_auth.php depends on this)
    // 📧 queueEmail() - fn_auth.php's registerUser()/requestPasswordReset()
    // both call queueEmail() (function_exists()-guarded, fails open if
    // absent). This MUST be loaded globally (not just by the API router)
    // because register/index.php and forgot-password/index.php's own no-JS
    // <form> POST handling calls those SAME engine functions directly - a
    // bug caught during this stage's scratch-DB verification: without this,
    // the no-JS registration path silently created the account but never
    // queued a verification email (queueEmail() was undefined on that page).
    // It also provides the email queue runner functions (#112) used by
    // _includes/cron/process_email_queue.php and by Step 7 below.
    'fn_email.php',
    'fn_auth.php',           // 🔐 currentUser()/requireAuth()/session engine (Epic E3)
    'fn_features.php',       // 💳 planForUser()/limitFor()/featureEnabled() (Epic E4 framework, used by the account dashboard + generator "Save" gating)
    // 🔐 Epic E2 (#55/#19/#20/#28): the OIDC/OAuth2 SSO client engine is a
    // GLOBAL bootstrap include (not just an API-router file) so that
    // login/index.php, register/index.php, and account/index.php can all
    // call ssoProviderIsUsable()/ssoProviderUiMeta() to decide whether to
    // render a provider's "Sign in with ..." button - every provider is
    // INERT by default (sso_enabled + sso_<provider>_enabled both seed 0),
    // so loading this file globally attempts zero outbound network calls.
    'fn_oauth.php',
];

foreach ($functionFiles as $functionFile) {
    $filePath = CUERCODE_FUNCTIONS_DIR . $functionFile;

    if (file_exists($filePath)) {
        require_once $filePath;
    } else {
        // ⚠️ Log missing function file but continue
        // Some functions may not be critical for all operations
        error_log('CueRCode WARNING: Function file not found: ' . $filePath);
    }
}

// ==============================================================================
// 🍪 Step 4: Configure Session
// ==============================================================================
// Start PHP session with secure settings.
// Session must be started before CSRF token generation and user authentication.
// @see https://www.php.net/manual/en/session.security.ini.php
// @see https://www.php.net/manual/en/function.session-start.php
// ==============================================================================

if (session_status() === PHP_SESSION_NONE && php_sapi_name() !== 'cli') {
    // 🔧 Configure session parameters before starting
    // @see https://www.php.net/manual/en/function.session-set-cookie-params.php
    $sessionLifetime = 3600; // Default 1 hour

    // Try to get session lifetime from config (if config is loaded)
    if (function_exists('getConfig')) {
        try {
            $configLifetime = getConfig('session_lifetime', 3600);
            if (is_numeric($configLifetime) && (int) $configLifetime > 0) {
                $sessionLifetime = (int) $configLifetime;
            }
        } catch (\Throwable $e) {
            // Config not yet available (e.g., during installation) - use default
        }
    }

    // 🍪 Set session cookie parameters
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $isSecure = true;
    } else {
        $isSecure = false;
    }
    session_set_cookie_params([
        'lifetime' => $sessionLifetime,
        'path'     => '/',
        'domain'   => '',       // Current domain
        'secure'   => $isSecure,
        'httponly'  => true,     // Prevent JavaScript access to session cookie
        'samesite' => 'Lax',    // CSRF protection: Lax allows top-level GET navigations
    ]);

    // 🔑 Set session name (avoid default PHPSESSID which reveals PHP usage)
    session_name('CUERCODE_SID');

    // ▶️ Start the session
    session_start();
}

// ==============================================================================
// 🌍 Step 5: Set Timezone
// ==============================================================================
// Load timezone from configuration or default to UTC.
// @see https://www.php.net/manual/en/function.date-default-timezone-set.php
// @see https://www.php.net/manual/en/timezones.php
// ==============================================================================

$timezone = 'UTC';

if (function_exists('getConfig')) {
    try {
        $configTimezone = getConfig('site_timezone', 'UTC');
        if (!empty($configTimezone)) {
            $timezone = $configTimezone;
        }
    } catch (\Throwable $e) {
        // Config not available - use default UTC
    }
}

date_default_timezone_set($timezone);

// ==============================================================================
// 🐘 Step 6: Configure PHP Error Reporting
// ==============================================================================
// In debug mode: show all errors. In production: log only, don't display.
// The custom error handler (fn_error_handler.php) logs everything to tblErrorLog.
// @see https://www.php.net/manual/en/function.error-reporting.php
// ==============================================================================

// 📊 Always report all errors (the handler decides what to display vs log)
error_reporting(E_ALL);

// 🐛 Check debug mode and set display_errors accordingly
if (function_exists('isDebugMode') && isDebugMode()) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
}

// 📝 Always enable error logging
ini_set('log_errors', '1');

// ==============================================================================
// 📬 Step 7: Optional page-shutdown email sending (OFF by default, #112)
// ==============================================================================
// The dependable way to send queued emails is the command-line runner
// _includes/cron/process_email_queue.php, run by a DreamHost cron job every
// minute. This hook is a FALLBACK for hosts without cron: when the owner sets
// BOTH `email_queue_autoprocess` and `email_enabled` to 1, it sends up to 2
// queued emails at the end of a page request.
//
// 🔌 WHY it is wired in here: before #112 nothing called it, so setting
//    email_queue_autoprocess to 1 silently did nothing - a documented switch
//    that does nothing is the worst kind of failure.
// 🚫 WHY it is still OFF by default: it adds work to ordinary page visits,
//    only runs when people visit (so retries stall on a quiet site), and on a
//    server without fastcgi_finish_request() the visitor waits while emails
//    send. When off it costs one cached settings read per request.
// 🌐 WHY web requests only: command-line scripts (including the cron runner
//    itself) must never pick up extra work from a shutdown hook.
// It is safe alongside cron: both use the same locked, duplicate-proof batch.
// @see fn_email.php - maybeProcessEmailQueueOnShutdown()
// ==============================================================================

if (php_sapi_name() !== 'cli' && function_exists('maybeProcessEmailQueueOnShutdown')) {
    maybeProcessEmailQueueOnShutdown();
}

// ==============================================================================
// ✅ Bootstrap Complete
// ==============================================================================
// The application is now fully initialised with:
//   - Library autoloader registered (chillerlan/php-qrcode, FPDF)
//   - Database connection available via getDB() and dbQuery()
//   - All function files loaded (config, encryption, error handling, etc.)
//   - Session started with secure settings
//   - Timezone configured
//   - Error reporting configured
//   - Optional page-shutdown email sending registered (only when switched on, #112)
//
// Entry points can now use any function or class from the application.
// ==============================================================================
