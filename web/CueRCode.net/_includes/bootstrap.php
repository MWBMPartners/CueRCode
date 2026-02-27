<?php
/**
 * ==============================================================================
 * 🚀 CueRCode - Master Bootstrap / Initialisation File
 * ==============================================================================
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
// 1. Encryption (needed by config)
// 2. Config (needed by most other functions)
// 3. Error handler (needs config for debug mode check)
// 4. Activity log, debug, validation, response, CSRF (independent)
// @see https://www.php.net/manual/en/function.require-once.php
// ==============================================================================

// 📋 Function files to load (in dependency order)
$functionFiles = [
    'fn_encryption.php',     // 🔐 Encryption utilities (no dependencies)
    'fn_config.php',         // ⚙️ Configuration manager (depends on encryption)
    'fn_error_handler.php',  // 🚨 Error/exception handling (depends on config for debug mode)
    'fn_activity_log.php',   // 📊 Activity logging
    'fn_debug.php',          // 🐛 Debug mode utilities
    'fn_validation.php',     // ✅ Input validation
    'fn_response.php',       // 📤 Response formatting
    'fn_csrf.php',           // 🛡️ CSRF protection
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
// ✅ Bootstrap Complete
// ==============================================================================
// The application is now fully initialised with:
//   - Library autoloader registered (chillerlan/php-qrcode, FPDF)
//   - Database connection available via getDB() and dbQuery()
//   - All function files loaded (config, encryption, error handling, etc.)
//   - Session started with secure settings
//   - Timezone configured
//   - Error reporting configured
//
// Entry points can now use any function or class from the application.
// ==============================================================================
