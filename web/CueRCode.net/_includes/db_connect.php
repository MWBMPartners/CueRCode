<?php
/**
 * ==============================================================================
 * 🗄️ CueRCode - Database Connection Manager
 * ==============================================================================
 *
 * Provides a singleton-like database connection using MySQLi.
 * Loads credentials from _auth_keys/auth_creds.php (site-specific first,
 * then server-wide fallback). Uses prepared statements exclusively.
 *
 * @package     CueRCode
 * @subpackage  Database
 * @version     1.0.0
 * @since       Phase 1
 * @see         https://www.php.net/manual/en/book.mysqli.php
 * @see         https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php
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
// 📂 Load Database Credentials
// ==============================================================================
// Site-specific credentials take priority over server-wide defaults.
// Constants are only defined once; if the site-specific file defines them,
// the server-wide file's define() calls will safely be ignored.
// @see https://www.php.net/manual/en/function.defined.php
// ==============================================================================

// 📌 Determine paths using platform-neutral constants
$ds = DIRECTORY_SEPARATOR;

// Site-specific credentials path (relative to this file's location)
// This file is at: CueRCode/_includes/db_connect.php
// Credentials at:  CueRCode/_auth_keys/auth_creds.php
$siteCredsPath = dirname(__DIR__) . $ds . '_auth_keys' . $ds . 'auth_creds.php';

// Server-wide credentials path (fallback)
// Located at: <server>/_auth_keys/auth_creds.php
$serverCredsPath = dirname(__DIR__, 2) . $ds . '_auth_keys' . $ds . 'auth_creds.php';

// 🔑 Load site-specific credentials first (if available)
if (file_exists($siteCredsPath)) {
    require_once $siteCredsPath;
}

// 🔑 Load server-wide credentials as fallback
// Constants already defined by site-specific file won't be overwritten
if (file_exists($serverCredsPath)) {
    require_once $serverCredsPath;
}

// ==============================================================================
// ✅ Verify Required Constants
// ==============================================================================
if (!defined('DB_HOST') || !defined('DB_NAME') || !defined('DB_USER') || !defined('DB_PASS')) {
    // ⚠️ Cannot connect without credentials
    // Log this error if possible (error handler may not be loaded yet)
    error_log(
        'CueRCode FATAL: Database credentials not found. '
        . 'Ensure auth_creds.php exists in _auth_keys/ with DB_HOST, DB_NAME, DB_USER, DB_PASS defined.'
    );

    // Return a generic error to the user (do not expose credential details)
    if (php_sapi_name() !== 'cli') {
        header('HTTP/1.1 503 Service Unavailable');
        echo '<!DOCTYPE html><html><head><title>Service Unavailable</title></head>'
            . '<body><h1>Service Temporarily Unavailable</h1>'
            . '<p>The service is currently unable to connect to its database. '
            . 'Please contact the administrator.</p></body></html>';
    }
    exit(1);
}

// ==============================================================================
// 🗄️ Database Connection Function
// ==============================================================================
// Returns a MySQLi connection instance. Uses a static variable to maintain
// a single connection per request (singleton pattern).
//
// @return \mysqli The MySQLi connection object
// @throws \RuntimeException If the connection fails
//
// @see https://www.php.net/manual/en/mysqli.construct.php
// @see https://www.php.net/manual/en/mysqli.set-charset.php
// ==============================================================================

/**
 * 🗄️ Get the database connection (singleton)
 *
 * Creates a new MySQLi connection on first call, returns the existing
 * connection on subsequent calls within the same request.
 *
 * @return \mysqli The active database connection
 */
function getDB(): \mysqli
{
    // 📌 Static variable persists across function calls within the same request
    // @see https://www.php.net/manual/en/language.variables.scope.php#language.variables.scope.static
    static $db = null;

    // 🔄 Return existing connection if still alive
    if ($db !== null && $db->ping()) {
        return $db;
    }

    // 📌 Get connection parameters from constants
    $host    = DB_HOST;
    $user    = DB_USER;
    $pass    = DB_PASS;
    $name    = DB_NAME;
    if (defined('DB_PORT')) {
        $port = (int) DB_PORT;
    } else {
        $port = 3306;
    }
    if (defined('DB_CHARSET')) {
        $charset = DB_CHARSET;
    } else {
        $charset = 'utf8mb4';
    }

    // 🔌 Suppress connection warnings - we handle errors explicitly
    // @see https://www.php.net/manual/en/mysqli.construct.php
    $db = @new \mysqli($host, $user, $pass, $name, $port);

    // ❌ Check for connection errors
    if ($db->connect_error) {
        $errorMsg = 'Database connection failed: ' . $db->connect_error
            . ' (Error ' . $db->connect_errno . ')';

        // Log to PHP error log (database error handler may not be available yet)
        error_log('CueRCode DB ERROR: ' . $errorMsg);

        // Attempt to log to database error handler if available
        if (function_exists('logError')) {
            logError('critical', 'Database Connection Failed', $errorMsg);
        }

        throw new \RuntimeException($errorMsg);
    }

    // ==================================================================
    // ⚙️ Configure Connection Settings
    // ==================================================================

    // 🔤 Set character encoding to utf8mb4 for full Unicode support (including emojis)
    // @see https://www.php.net/manual/en/mysqli.set-charset.php
    if (!$db->set_charset($charset)) {
        error_log('CueRCode DB WARNING: Failed to set charset to ' . $charset . ': ' . $db->error);
    }

    // 🔧 Set SQL mode for strict data handling
    // STRICT_TRANS_TABLES: Strict mode for transactional storage engines
    // NO_ZERO_IN_DATE: Don't allow dates with zero parts
    // NO_ZERO_DATE: Don't allow '0000-00-00' as a date
    // ERROR_FOR_DIVISION_BY_ZERO: Produce error for division by zero
    // NO_ENGINE_SUBSTITUTION: Don't substitute storage engine if specified engine unavailable
    // @see https://dev.mysql.com/doc/refman/8.0/en/sql-mode.html
    $db->query("SET SQL_MODE = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

    // ⏱️ Set session timezone to UTC for consistent timestamp handling
    // @see https://dev.mysql.com/doc/refman/8.0/en/time-zone-support.html
    $db->query("SET time_zone = '+00:00'");

    return $db;
}

// ==============================================================================
// 🔌 Close Database Connection
// ==============================================================================

/**
 * 🔌 Close the database connection
 *
 * Should be called when the database connection is no longer needed.
 * The connection will be automatically closed when the script ends,
 * but explicit closure is good practice.
 *
 * @return void
 */
function closeDB(): void
{
    try {
        $db = getDB();
        $db->close();
    } catch (\RuntimeException $e) {
        // Connection was already closed or never established - that's OK
    }
}

// ==============================================================================
// 📊 Database Utility: Execute Prepared Statement
// ==============================================================================

/**
 * 📊 Execute a prepared statement and return the result
 *
 * Convenience function that prepares a statement, binds parameters,
 * executes, and returns the result. Uses prepared statements exclusively
 * for SQL injection prevention.
 *
 * @param string $sql    The SQL query with ? placeholders
 * @param string $types  The parameter types string (e.g., 'ssi' for string, string, int)
 * @param array  $params The parameter values to bind
 *
 * @return \mysqli_stmt The executed statement (caller must close it)
 *
 * @throws \RuntimeException If preparation or execution fails
 *
 * @see https://www.php.net/manual/en/mysqli.prepare.php
 * @see https://www.php.net/manual/en/mysqli-stmt.bind-param.php
 *
 * @example
 *   // Select a setting by key
 *   $stmt = dbQuery(
 *       "SELECT settingValue FROM tblSettings WHERE settingKey = ?",
 *       "s",
 *       ["site_name"]
 *   );
 *   $result = $stmt->get_result();
 *   $row = $result->fetch_assoc();
 *   $stmt->close();
 */
function dbQuery(string $sql, string $types = '', array $params = []): \mysqli_stmt
{
    $db = getDB();

    // 📝 Prepare the statement
    $stmt = $db->prepare($sql);

    if ($stmt === false) {
        $errorMsg = 'Failed to prepare SQL statement: ' . $db->error
            . ' | SQL: ' . substr($sql, 0, 500);

        if (function_exists('logError')) {
            logError('error', 'SQL Prepare Failed', $errorMsg);
        }

        throw new \RuntimeException($errorMsg);
    }

    // 🔗 Bind parameters if provided
    if (!empty($types) && !empty($params)) {
        // @see https://www.php.net/manual/en/mysqli-stmt.bind-param.php
        $stmt->bind_param($types, ...$params);
    }

    // ▶️ Execute the statement
    if (!$stmt->execute()) {
        $errorMsg = 'Failed to execute SQL statement: ' . $stmt->error
            . ' | SQL: ' . substr($sql, 0, 500);

        if (function_exists('logError')) {
            logError('error', 'SQL Execute Failed', $errorMsg);
        }

        $stmt->close();
        throw new \RuntimeException($errorMsg);
    }

    return $stmt;
}
