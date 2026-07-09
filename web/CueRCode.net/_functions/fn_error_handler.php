<?php
/**
 * ==============================================================================
 * 🚨 CueRCode - Error and Exception Handler
 * ==============================================================================
 *
 * Path: _functions/fn_error_handler.php
 *
 * Registers custom error, exception, and shutdown handlers to capture all
 * PHP errors and exceptions. Errors are logged to the tblErrorLog database
 * table with full context (backtrace, request details, headers, etc.).
 *
 * In debug mode, errors are displayed on-page with formatted output.
 * In production mode, errors are logged silently and a generic message
 * is shown to the user.
 *
 * Request headers are sanitised before logging: the Authorization header
 * value is redacted and Cookie values are replaced with "[REDACTED]".
 *
 * @package     CueRCode
 * @subpackage  Error Handling
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 1
 * @see         https://www.php.net/manual/en/function.set-error-handler.php
 * @see         https://www.php.net/manual/en/function.set-exception-handler.php
 * @see         https://www.php.net/manual/en/function.register-shutdown-function.php
 * @see         https://www.php.net/manual/en/errorfunc.constants.php
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
// 📋 PHP Error Code to Severity Mapping
// ==============================================================================
// Maps PHP predefined error constants to PSR-3 severity levels matching
// the tblErrorLog.errorSeverity ENUM values.
// @see https://www.php.net/manual/en/errorfunc.constants.php
// @see https://www.php-fig.org/psr/psr-3/#5-psrlogloglevel
// ==============================================================================

// 🛡️ B-052: E_STRICT is DEPRECATED as of PHP 8.4 — even *naming* the constant
//     (e.g. as an array key) emits a "Constant E_STRICT is deprecated" notice.
//     PHP no longer raises any E_STRICT errors on 8.4+, so the map key is only
//     useful on older runtimes. We therefore add it conditionally, and read its
//     value via constant('E_STRICT') guarded by a version check so the constant
//     is only ever referenced on PHP < 8.4 (where it is not deprecated). This
//     keeps the historical severity-map behaviour for every other code intact
//     while emitting ZERO deprecation notices when the file is loaded on 8.4+.
// @see https://www.php.net/manual/en/migration84.deprecated.php
// @see https://www.php.net/manual/en/function.constant.php

/** @var array<int,string> Map of PHP error constants to PSR-3 severity strings */
$cuercodeErrorSeverityMap = [
    E_ERROR             => 'critical',   // 🔴 Fatal run-time error
    E_WARNING           => 'warning',    // 🟡 Run-time warning
    E_PARSE             => 'critical',   // 🔴 Compile-time parse error
    E_NOTICE            => 'notice',     // 🔵 Run-time notice
    E_CORE_ERROR        => 'emergency',  // 🔴 Fatal error during PHP startup
    E_CORE_WARNING      => 'warning',    // 🟡 Warning during PHP startup
    E_COMPILE_ERROR     => 'emergency',  // 🔴 Fatal compile-time error
    E_COMPILE_WARNING   => 'warning',    // 🟡 Compile-time warning
    E_USER_ERROR        => 'error',      // 🟠 User-triggered error
    E_USER_WARNING      => 'warning',    // 🟡 User-triggered warning
    E_USER_NOTICE       => 'notice',     // 🔵 User-triggered notice
    E_RECOVERABLE_ERROR => 'error',      // 🟠 Catchable fatal error
    E_DEPRECATED        => 'info',       // 🔵 Deprecated feature notice
    E_USER_DEPRECATED   => 'info',       // 🔵 User-triggered deprecation
];

// 🔵 Add the legacy E_STRICT severity ONLY on PHP versions where the constant
//    is still live (< 8.4). constant('E_STRICT') avoids a literal reference to
//    the deprecated constant on newer PHP, suppressing the deprecation notice.
if (version_compare(PHP_VERSION, '8.4', '<') && defined('E_STRICT')) {
    $cuercodeErrorSeverityMap[constant('E_STRICT')] = 'info'; // 🔵 Strict standards suggestion
}

define('CUERCODE_ERROR_SEVERITY_MAP', $cuercodeErrorSeverityMap);
unset($cuercodeErrorSeverityMap);

// ==============================================================================
// ⚙️ Register Custom Error Handler
// ==============================================================================
// Converts PHP errors (warnings, notices, etc.) into logged entries.
// Does NOT handle fatal errors (those are caught by the shutdown function).
// @see https://www.php.net/manual/en/function.set-error-handler.php
// ==============================================================================

set_error_handler(function (
    int    $errno,
    string $errstr,
    string $errfile,
    int    $errline
): bool {
    // 🚫 Respect the error_reporting level set in php.ini or by the @ operator
    // If the error is suppressed via @, error_reporting() returns 0.
    // @see https://www.php.net/manual/en/language.operators.errorcontrol.php
    if (!(error_reporting() & $errno)) {
        return false;
    }

    // 🔍 Map the PHP error code to a human-readable constant name
    // @see https://www.php.net/manual/en/errorfunc.constants.php
    $errorCodeName = getErrorCodeName($errno);

    // 🔍 Map to PSR-3 severity
    if (isset(CUERCODE_ERROR_SEVERITY_MAP[$errno])) {
        $severity = CUERCODE_ERROR_SEVERITY_MAP[$errno];
    } else {
        $severity = 'error';
    }

    // 📝 Log the error to the database
    logError(
        $severity,
        $errstr,
        'PHP Error [' . $errorCodeName . '] in ' . $errfile . ' on line ' . $errline,
        [
            'errorCode'  => $errorCodeName,
            'fileName'   => $errfile,
            'lineNumber' => $errline,
        ]
    );

    // 🖥️ Display error on-page if debug mode is active
    if (function_exists('isDebugMode') && isDebugMode()) {
        displayDebugError($severity, $errstr, $errfile, $errline, $errorCodeName);
    }

    // ✅ Return true to prevent PHP's built-in error handler from running
    // @see https://www.php.net/manual/en/function.set-error-handler.php
    return true;
});

// ==============================================================================
// ⚙️ Register Custom Exception Handler
// ==============================================================================
// Catches any uncaught exception that bubbles up to the top level.
// @see https://www.php.net/manual/en/function.set-exception-handler.php
// ==============================================================================

set_exception_handler(function (\Throwable $exception): void {

    // 📝 Build the error detail string with exception class, code, and trace
    $detail = 'Uncaught ' . get_class($exception) . ': ' . $exception->getMessage()
        . PHP_EOL . 'Code: ' . $exception->getCode()
        . PHP_EOL . 'File: ' . $exception->getFile() . ':' . $exception->getLine()
        . PHP_EOL . PHP_EOL . 'Stack Trace:' . PHP_EOL . $exception->getTraceAsString();

    // 📝 Log the exception
    logError(
        'critical',
        'Uncaught Exception: ' . $exception->getMessage(),
        $detail,
        [
            'exceptionClass' => get_class($exception),
            'errorCode'      => (string) $exception->getCode(),
            'fileName'       => $exception->getFile(),
            'lineNumber'     => $exception->getLine(),
            'backtrace'      => $exception->getTraceAsString(),
        ]
    );

    // 🖥️ Display or suppress based on debug mode
    if (function_exists('isDebugMode') && isDebugMode()) {
        displayDebugError(
            'critical',
            'Uncaught Exception: ' . $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            get_class($exception),
            $exception->getTraceAsString()
        );
    } else {
        // 🔒 Production: send generic error response (do not expose details)
        if (!headers_sent()) {
            header('HTTP/1.1 500 Internal Server Error');
        }

        // 📌 Only output if no content has been sent yet
        if (ob_get_level() === 0 || ob_get_length() === 0) {
            echo '<!DOCTYPE html><html><head><title>Server Error</title></head>'
                . '<body><h1>An unexpected error occurred</h1>'
                . '<p>The application encountered an internal error. '
                . 'Please try again later or contact the administrator.</p></body></html>';
        }
    }
});

// ==============================================================================
// ⚙️ Register Shutdown Function (Fatal Error Catcher)
// ==============================================================================
// PHP's set_error_handler() cannot catch fatal errors (E_ERROR, E_PARSE, etc.).
// The shutdown function runs after script termination and can inspect the last
// error using error_get_last().
// @see https://www.php.net/manual/en/function.register-shutdown-function.php
// @see https://www.php.net/manual/en/function.error-get-last.php
// ==============================================================================

register_shutdown_function(function (): void {

    // 🔍 Check if the script terminated due to an error
    // @see https://www.php.net/manual/en/function.error-get-last.php
    $lastError = error_get_last();

    if ($lastError === null) {
        return;
    }

    // 📌 Only handle fatal-class errors that set_error_handler() cannot catch
    // @see https://www.php.net/manual/en/errorfunc.constants.php
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_CORE_WARNING, E_COMPILE_ERROR, E_COMPILE_WARNING];

    if (!in_array($lastError['type'], $fatalTypes, true)) {
        return;
    }

    // 🔍 Map to severity and error code name
    if (isset(CUERCODE_ERROR_SEVERITY_MAP[$lastError['type']])) {
        $severity = CUERCODE_ERROR_SEVERITY_MAP[$lastError['type']];
    } else {
        $severity = 'emergency';
    }
    $errorCodeName = getErrorCodeName($lastError['type']);

    // 📝 Log the fatal error
    logError(
        $severity,
        'Fatal Error: ' . $lastError['message'],
        'PHP Fatal [' . $errorCodeName . '] in ' . $lastError['file']
            . ' on line ' . $lastError['line'],
        [
            'errorCode'  => $errorCodeName,
            'fileName'   => $lastError['file'],
            'lineNumber' => $lastError['line'],
            'isFatal'    => true,
        ]
    );
});

// ==============================================================================
// 📝 logError() - Log an error to tblErrorLog
// ==============================================================================

/**
 * 📝 Log an error entry to the tblErrorLog database table
 *
 * Records a comprehensive error log entry including severity, title, detail,
 * backtrace, request context (URL, method, IP, user agent), sanitised
 * headers, PHP version, and any additional data.
 *
 * This function is designed to be resilient: if the database is unavailable,
 * it falls back to PHP's built-in error_log() function.
 *
 * @param string $severity The PSR-3 severity level
 *                         (emergency|alert|critical|error|warning|notice|info|debug)
 * @param string $title    Brief summary of the error
 * @param string $detail   Full error message and details (default: '')
 * @param array  $extra    Additional context data:
 *                         - 'errorCode'  (string) PHP error code or custom code
 *                         - 'fileName'   (string) Source file path
 *                         - 'lineNumber' (int)    Line number in source file
 *                         - 'backtrace'  (string) Stack trace string
 *                         - Any other key-value pairs stored in additionalData JSON
 *
 * @return void
 *
 * @see https://www.php.net/manual/en/function.debug-backtrace.php
 * @see https://www.php.net/manual/en/reserved.variables.server.php
 * @see https://www.php.net/manual/en/function.error-log.php
 *
 * @example
 *   logError('error', 'Database query failed', 'SELECT * FROM missing_table', [
 *       'errorCode' => 'SQL_ERROR',
 *       'fileName'  => __FILE__,
 *       'lineNumber' => __LINE__,
 *   ]);
 */
function logError(
    string $severity,
    string $title,
    string $detail = '',
    array  $extra = []
): void {
    // 🛡️ Prevent infinite recursion if logError() itself triggers an error
    // Uses a static flag to detect re-entrant calls.
    static $isLogging = false;

    if ($isLogging) {
        // 🔄 Fallback to PHP's built-in error log to avoid infinite loop
        // 🔒 F-008: mask SQL text + absolute filesystem paths before they reach
        //     the plaintext error_log() channel. Full, unmasked detail still
        //     goes to tblErrorLog (access-controlled DB) on the normal path.
        // @see https://www.php.net/manual/en/function.error-log.php
        error_log(
            'CueRCode [' . $severity . '] (re-entrant): '
            . maskSensitiveForLog($title) . ' | ' . maskSensitiveForLog($detail)
        );
        return;
    }

    $isLogging = true;

    // ==================================================================
    // 📋 Gather request context
    // @see https://www.php.net/manual/en/reserved.variables.server.php
    // ==================================================================

    // 🌐 Build the full request URL
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $scheme = 'https';
    } else {
        $scheme = 'http';
    }
    if (isset($_SERVER['HTTP_HOST'])) {
        $host = $_SERVER['HTTP_HOST'];
    } else {
        if (isset($_SERVER['SERVER_NAME'])) {
            $host = $_SERVER['SERVER_NAME'];
        } else {
            $host = 'unknown';
        }
    }
    if (isset($_SERVER['REQUEST_URI'])) {
        $requestURI = $_SERVER['REQUEST_URI'];
    } else {
        $requestURI = '/';
    }
    $requestURL = $scheme . '://' . $host . $requestURI;

    // 📨 HTTP method
    if (isset($_SERVER['REQUEST_METHOD'])) {
        $requestMethod = $_SERVER['REQUEST_METHOD'];
    } else {
        $requestMethod = 'CLI';
    }

    // 🌍 Client IP address - use getClientIP() if available (trusted proxy aware),
    // otherwise fall back to REMOTE_ADDR only (prevents IP spoofing via headers).
    // @see fn_api_auth.php - getClientIP()
    // @see https://www.php.net/manual/en/reserved.variables.server.php
    if (function_exists('getClientIP')) {
        $requestIP = getClientIP();
    } else {
        if (isset($_SERVER['REMOTE_ADDR'])) {
            $requestIP = $_SERVER['REMOTE_ADDR'];
        } else {
            $requestIP = 'unknown';
        }
    }

    // 🖥️ User agent string
    if (isset($_SERVER['HTTP_USER_AGENT'])) {
        $userAgent = $_SERVER['HTTP_USER_AGENT'];
    } else {
        $userAgent = 'unknown';
    }

    // 🎫 Session ID (if session is active)
    // @see https://www.php.net/manual/en/function.session-id.php
    if (session_status() === PHP_SESSION_ACTIVE) {
        $sessionID = session_id();
    } else {
        $sessionID = null;
    }

    // 👤 User ID from session (if available)
    if (isset($_SESSION['userID'])) {
        $userID = $_SESSION['userID'];
    } else {
        $userID = null;
    }

    // 📋 Sanitise request headers (redact sensitive values)
    $sanitisedHeaders = sanitiseRequestHeaders();

    // 🔍 Generate a backtrace if not provided in $extra
    // @see https://www.php.net/manual/en/function.debug-backtrace.php
    if (isset($extra['backtrace'])) {
        $backtrace = $extra['backtrace'];
    } else {
        $backtrace = null;
    }
    if ($backtrace === null) {
        // 📌 Limit backtrace depth to 10 frames and exclude arguments
        // DEBUG_BACKTRACE_IGNORE_ARGS prevents logging sensitive function arguments
        $traceArray = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
        $backtrace  = formatBacktrace($traceArray);
    }

    // 📄 Extract specific fields from $extra
    if (isset($extra['errorCode'])) {
        $errorCode = $extra['errorCode'];
    } else {
        $errorCode = null;
    }
    if (isset($extra['fileName'])) {
        $fileName = $extra['fileName'];
    } else {
        $fileName = null;
    }
    if (isset($extra['lineNumber'])) {
        $lineNumber = $extra['lineNumber'];
    } else {
        $lineNumber = null;
    }

    // 📦 Build additional data JSON (remove fields already stored in dedicated columns)
    $additionalData = array_diff_key($extra, array_flip([
        'errorCode', 'fileName', 'lineNumber', 'backtrace'
    ]));

    // 📦 Encode additional data as JSON or set to null if empty
    // @see https://www.php.net/manual/en/function.json-encode.php
    if (!empty($additionalData)) {
        $additionalDataJSON = json_encode($additionalData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } else {
        $additionalDataJSON = null;
    }

    // ==================================================================
    // 🗄️ Insert into tblErrorLog
    // @see https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php
    // ==================================================================
    try {
        // ✅ Verify dbQuery() is available (db_connect.php must be loaded)
        if (!function_exists('dbQuery')) {
            throw new \RuntimeException('dbQuery() not available - db_connect.php not loaded');
        }

        if (isset($_SERVER['SERVER_NAME'])) {
            $serverName = $_SERVER['SERVER_NAME'];
        } else {
            $serverName = 'unknown';
        }
        $stmt = dbQuery(
            "INSERT INTO `tblErrorLog` ("
            . "`errorSeverity`, `errorCode`, `errorTitle`, `errorDetail`, `errorBacktrace`, "
            . "`requestURL`, `requestMethod`, `requestHeaders`, `requestIP`, `userAgent`, "
            . "`userID`, `sessionID`, `fileName`, `lineNumber`, `phpVersion`, "
            . "`serverName`, `additionalData`"
            . ") VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            'ssssssssssisssiss',
            [
                $severity,                       // errorSeverity (ENUM)
                $errorCode,                      // errorCode
                mb_substr($title, 0, 500),       // errorTitle (VARCHAR 500)
                $detail,                         // errorDetail (TEXT)
                $backtrace,                      // errorBacktrace (TEXT)
                mb_substr($requestURL, 0, 2048), // requestURL (VARCHAR 2048)
                $requestMethod,                  // requestMethod (VARCHAR 10)
                $sanitisedHeaders,               // requestHeaders (TEXT)
                $requestIP,                      // requestIP (VARCHAR 45)
                mb_substr($userAgent, 0, 500),   // userAgent (VARCHAR 500)
                $userID,                         // userID (INT UNSIGNED, nullable)
                $sessionID,                      // sessionID (VARCHAR 128)
                $fileName,                       // fileName (VARCHAR 500)
                $lineNumber,                     // lineNumber (INT UNSIGNED, nullable)
                PHP_VERSION,                     // phpVersion
                $serverName,                     // serverName
                $additionalDataJSON,             // additionalData (JSON)
            ]
        );
        $stmt->close();
    } catch (\Throwable $e) {
        // ❌ Database logging failed - fall back to PHP error_log
        // This ensures errors are always captured somewhere, even if the
        // database is down or not yet initialised (pre-DB-init path).
        // 🔒 F-008: this is the plaintext fallback channel (a shared server
        //     error_log, not access-controlled like tblErrorLog). $detail can
        //     carry raw SQL statement text (db_connect.php throws RuntimeException
        //     messages containing "SQL: <query>") and absolute filesystem paths
        //     from PHP error/exception messages. Mask both BEFORE they hit the
        //     log: SQL bodies are redacted to a placeholder and absolute paths
        //     are abbreviated to web-root-relative form. Full, unmasked detail
        //     is preserved only on the DB path above (which failed here).
        // @see https://www.php.net/manual/en/function.error-log.php
        error_log(
            'CueRCode [' . $severity . ']: ' . maskSensitiveForLog($title)
            . ' | Detail: ' . maskSensitiveForLog($detail)
            . ' | DB Log Error: ' . maskSensitiveForLog($e->getMessage())
        );
    }

    // 🔓 Reset the re-entrancy guard
    $isLogging = false;
}

// ==============================================================================
// 🔒 maskSensitiveForLog() - Redact SQL + abbreviate paths for the error_log() fallback
// ==============================================================================

/**
 * 🔒 Mask sensitive substrings before they are written to PHP's error_log()
 *
 * The error_log() fallback channel (used only when the access-controlled
 * tblErrorLog DB write is impossible — e.g. DB down, pre-bootstrap, or a
 * re-entrant logging loop) typically lands in a shared / less-protected
 * server log. To avoid leaking sensitive technical detail there (F-008),
 * this helper performs two reductions on the plaintext message:
 *
 *   1. SQL statement bodies — db_connect.php throws RuntimeException messages
 *      shaped as "... | SQL: <query text>". The query text can embed schema
 *      details and (in non-prepared paths) literal values. Everything after a
 *      "SQL:" marker, up to the next pipe delimiter or end of string, is
 *      replaced with a fixed "[SQL REDACTED]" placeholder. Common standalone
 *      DML/DDL verbs at the start of a fragment are also blanked as a backstop.
 *
 *   2. Absolute filesystem paths — PHP error/exception messages contain
 *      absolute paths (e.g. /home/user/example.com/.../_functions/foo.php)
 *      that disclose the server layout. Everything up to and including the
 *      site web root is stripped so only a web-root-relative path remains.
 *      The web root is derived from this file's own location (this file lives
 *      at <webroot>/_functions/fn_error_handler.php).
 *
 * NOTE: Full, UNMASKED detail is still recorded in tblErrorLog on the normal
 * path — this masking applies ONLY to the plaintext error_log() fallback, so
 * no diagnostic capability is lost for anyone with DB access. This is a
 * defence-in-depth reduction of at-rest leakage, not a security boundary.
 *
 * @param string $message The raw log message (title, detail, or exception text)
 *
 * @return string The message with SQL bodies redacted and absolute paths
 *                abbreviated to web-root-relative form
 *
 * @see https://www.php.net/manual/en/function.error-log.php
 * @see https://www.php.net/manual/en/function.preg-replace.php
 */
function maskSensitiveForLog(string $message): string
{
    // 🛑 Fast path: nothing to mask on an empty string
    if ($message === '') {
        return $message;
    }

    $masked = $message;

    // ----------------------------------------------------------------------
    // 1️⃣ Redact SQL statement bodies after a "SQL:" marker
    // ----------------------------------------------------------------------
    // Matches "SQL:" (any case, optional surrounding whitespace) and replaces
    // the query body up to the next " | " field delimiter (or end of string)
    // with a fixed placeholder. The non-greedy [^|]* keeps masking scoped to
    // the SQL field so other appended context (e.g. "| DB Log Error: ...") is
    // preserved intact.
    $masked = preg_replace(
        '/SQL:\s*[^|]*/i',
        'SQL: [SQL REDACTED]',
        $masked
    );

    // 🛡️ Backstop: blank any remaining bare SQL statements that were NOT
    // introduced by a "SQL:" marker (e.g. a raw query echoed elsewhere).
    // Anchored to a fragment start (line start or after a pipe) so it does not
    // clobber prose that merely contains a verb like "selected" or "updated".
    $masked = preg_replace(
        '/(^|\|\s*)(SELECT|INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP|TRUNCATE)\b[^|]*/i',
        '$1[SQL REDACTED]',
        $masked
    );

    // ----------------------------------------------------------------------
    // 2️⃣ Abbreviate absolute filesystem paths to web-root-relative form
    // ----------------------------------------------------------------------
    // This file lives at <webroot>/_functions/fn_error_handler.php, so the web
    // root is two directories up from __DIR__. Strip the web-root prefix from
    // any occurrence so logs show e.g. "_functions/foo.php" not the full
    // /home/.../<site>/_functions/foo.php server path.
    $webRoot = dirname(__DIR__);

    if ($webRoot !== '' && $webRoot !== DIRECTORY_SEPARATOR) {
        // 🔁 Normalise to forward slashes for a single, OS-independent match.
        $needle = str_replace('\\', '/', $webRoot);
        $haystack = str_replace('\\', '/', $masked);

        // Only rewrite if the web-root prefix actually appears, to avoid
        // disturbing messages that contain no absolute paths at all.
        if (strpos($haystack, $needle) !== false) {
            // Replace "<webroot>/" with "" (relative) and a bare "<webroot>"
            // with "." so a trailing-slash-less reference still reads sensibly.
            $masked = str_replace($needle . '/', '', $haystack);
            $masked = str_replace($needle, '.', $masked);
        }
    }

    return $masked;
}

// ==============================================================================
// 🔒 sanitiseRequestHeaders() - Sanitise headers for safe logging
// ==============================================================================

/**
 * 🔒 Sanitise HTTP request headers for safe logging
 *
 * Collects all request headers and redacts sensitive values:
 *   - Authorization header: value replaced with "[REDACTED]"
 *   - Cookie header: value replaced with "[REDACTED]"
 *   - X-API-Key header: value replaced with "[REDACTED]"
 *
 * @return string JSON-encoded sanitised headers
 *
 * @see https://www.php.net/manual/en/function.getallheaders.php
 * @see https://www.php.net/manual/en/function.apache-request-headers.php
 */
function sanitiseRequestHeaders(): string
{
    // 📋 Get all request headers
    // getallheaders() works with Apache and FastCGI (PHP 7.0+)
    // @see https://www.php.net/manual/en/function.getallheaders.php
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
    } else {
        // 🔄 Fallback: build headers from $_SERVER for CGI environments
        // @see https://www.php.net/manual/en/reserved.variables.server.php
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                // Convert HTTP_HEADER_NAME to Header-Name format
                $headerName = str_replace(
                    ' ',
                    '-',
                    ucwords(strtolower(str_replace('_', ' ', substr($key, 5))))
                );
                $headers[$headerName] = $value;
            }
        }
    }

    // 🔐 List of headers whose values must be redacted
    // These headers contain authentication tokens, session identifiers,
    // or API credentials that should never appear in logs.
    $sensitiveHeaders = [
        'authorization',
        'cookie',
        'x-api-key',
        'x-csrf-token',
        'proxy-authorization',
    ];

    // 🔒 Redact sensitive header values
    foreach ($headers as $name => $value) {
        if (in_array(strtolower($name), $sensitiveHeaders, true)) {
            $headers[$name] = '[REDACTED]';
        }
    }

    // 📦 Return as JSON string for storage in the TEXT column
    // @see https://www.php.net/manual/en/function.json-encode.php
    return json_encode($headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

// ==============================================================================
// 🔍 getErrorCodeName() - Map PHP error code to constant name
// ==============================================================================

/**
 * 🔍 Get the human-readable name for a PHP error code constant
 *
 * Converts numeric PHP error codes (e.g., 2) to their constant names
 * (e.g., 'E_WARNING') for easier identification in logs.
 *
 * @param int $code The PHP error code (e.g., E_ERROR, E_WARNING)
 *
 * @return string The constant name, or 'E_UNKNOWN (code)' if not recognised
 *
 * @see https://www.php.net/manual/en/errorfunc.constants.php
 */
function getErrorCodeName(int $code): string
{
    // 📋 Map of PHP error constants to their string names
    // @see https://www.php.net/manual/en/errorfunc.constants.php
    $names = [
        E_ERROR             => 'E_ERROR',
        E_WARNING           => 'E_WARNING',
        E_PARSE             => 'E_PARSE',
        E_NOTICE            => 'E_NOTICE',
        E_CORE_ERROR        => 'E_CORE_ERROR',
        E_CORE_WARNING      => 'E_CORE_WARNING',
        E_COMPILE_ERROR     => 'E_COMPILE_ERROR',
        E_COMPILE_WARNING   => 'E_COMPILE_WARNING',
        E_USER_ERROR        => 'E_USER_ERROR',
        E_USER_WARNING      => 'E_USER_WARNING',
        E_USER_NOTICE       => 'E_USER_NOTICE',
        E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
        E_DEPRECATED        => 'E_DEPRECATED',
        E_USER_DEPRECATED   => 'E_USER_DEPRECATED',
    ];

    // 🛡️ B-052: add the deprecated E_STRICT name only on PHP < 8.4, reading the
    //     constant value via constant() so the deprecated constant is never
    //     referenced literally on newer runtimes (no deprecation notice).
    // @see https://www.php.net/manual/en/migration84.deprecated.php
    if (version_compare(PHP_VERSION, '8.4', '<') && defined('E_STRICT')) {
        $names[constant('E_STRICT')] = 'E_STRICT';
    }

    if (isset($names[$code])) {
        return $names[$code];
    } else {
        return 'E_UNKNOWN (' . $code . ')';
    }
}

// ==============================================================================
// 🔍 formatBacktrace() - Format a debug backtrace array into a readable string
// ==============================================================================

/**
 * 🔍 Format a debug_backtrace() array into a readable string
 *
 * Converts the structured array from debug_backtrace() into a formatted
 * string similar to Exception::getTraceAsString().
 *
 * @param array $trace The array returned by debug_backtrace()
 *
 * @return string Formatted backtrace string
 *
 * @see https://www.php.net/manual/en/function.debug-backtrace.php
 */
function formatBacktrace(array $trace): string
{
    $output = '';
    $index  = 0;

    foreach ($trace as $frame) {
        // 📌 Skip frames from this error handler file itself
        if (isset($frame['file'])) {
            $frameFile = $frame['file'];
        } else {
            $frameFile = 'unknown';
        }
        if ($frameFile === __FILE__) {
            continue;
        }

        if (isset($frame['file'])) {
            $traceFile = $frame['file'];
        } else {
            $traceFile = '[internal]';
        }
        if (isset($frame['line'])) {
            $traceLine = $frame['line'];
        } else {
            $traceLine = '?';
        }
        if (isset($frame['class'])) {
            $traceClass = $frame['class'];
        } else {
            $traceClass = '';
        }
        if (isset($frame['type'])) {
            $traceType = $frame['type'];
        } else {
            $traceType = '';
        }
        if (isset($frame['function'])) {
            $traceFunction = $frame['function'];
        } else {
            $traceFunction = 'unknown';
        }
        $output .= '#' . $index . ' '
            . $traceFile
            . ':' . $traceLine
            . ' '
            . $traceClass
            . $traceType
            . $traceFunction
            . '()' . PHP_EOL;

        $index++;
    }

    return $output;
}

// ==============================================================================
// 🖥️ displayDebugError() - Display formatted error output in debug mode
// ==============================================================================

/**
 * 🖥️ Display a formatted error message on-page when debug mode is active
 *
 * Outputs a styled HTML block with error details including severity,
 * message, file, line number, and optionally a stack trace.
 *
 * This function should only be called when isDebugMode() returns true.
 * In production, errors are logged silently without any on-page output.
 *
 * @param string      $severity  The PSR-3 severity level
 * @param string      $message   The error message
 * @param string      $file      The source file path
 * @param int         $line      The line number
 * @param string      $errorCode The error code or exception class name
 * @param string|null $trace     Optional stack trace string
 *
 * @return void
 */
function displayDebugError(
    string  $severity,
    string  $message,
    string  $file,
    int     $line,
    string  $errorCode = '',
    ?string $trace = null
): void {
    // 🎨 Map severity to a background colour for visual distinction
    $colorMap = [
        'emergency' => '#dc3545',  // 🔴 Red
        'alert'     => '#dc3545',  // 🔴 Red
        'critical'  => '#dc3545',  // 🔴 Red
        'error'     => '#fd7e14',  // 🟠 Orange
        'warning'   => '#ffc107',  // 🟡 Yellow
        'notice'    => '#17a2b8',  // 🔵 Cyan
        'info'      => '#6c757d',  // ⚫ Grey
        'debug'     => '#6c757d',  // ⚫ Grey
    ];

    if (isset($colorMap[$severity])) {
        $bgColor = $colorMap[$severity];
    } else {
        $bgColor = '#dc3545';
    }
    if (in_array($severity, ['warning'], true)) {
        $textColor = '#212529';
    } else {
        $textColor = '#ffffff';
    }

    // 📌 Escape all output for XSS prevention
    // @see https://www.php.net/manual/en/function.htmlspecialchars.php
    $eSeverity  = htmlspecialchars(strtoupper($severity), ENT_QUOTES, 'UTF-8');
    $eMessage   = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $eFile      = htmlspecialchars($file, ENT_QUOTES, 'UTF-8');
    $eLine      = (int) $line;
    $eErrorCode = htmlspecialchars($errorCode, ENT_QUOTES, 'UTF-8');

    // 🖥️ Output styled debug error block
    echo '<div style="margin:10px;padding:15px;border-left:5px solid ' . $bgColor
        . ';background:#f8f9fa;font-family:monospace;font-size:13px;line-height:1.5;'
        . 'border-radius:4px;overflow-x:auto;">' . PHP_EOL;

    echo '  <div style="display:inline-block;padding:2px 8px;background:' . $bgColor
        . ';color:' . $textColor . ';border-radius:3px;font-weight:bold;margin-bottom:8px;">'
        . $eSeverity . '</div>' . PHP_EOL;

    if ($eErrorCode !== '') {
        echo '  <span style="color:#6c757d;margin-left:8px;">[' . $eErrorCode . ']</span>' . PHP_EOL;
    }

    echo '  <div style="margin-top:5px;color:#212529;"><strong>' . $eMessage . '</strong></div>' . PHP_EOL;
    echo '  <div style="margin-top:5px;color:#6c757d;">File: ' . $eFile . ':' . $eLine . '</div>' . PHP_EOL;
    echo '  <div style="margin-top:3px;color:#6c757d;">Time: '
        . htmlspecialchars(date('Y-m-d H:i:s T'), ENT_QUOTES, 'UTF-8')
        . '</div>' . PHP_EOL;

    // 📋 Display stack trace if available
    if ($trace !== null && $trace !== '') {
        echo '  <details style="margin-top:10px;">' . PHP_EOL;
        echo '    <summary style="cursor:pointer;color:#495057;">Stack Trace</summary>' . PHP_EOL;
        echo '    <pre style="margin-top:5px;padding:10px;background:#e9ecef;border-radius:3px;'
            . 'font-size:12px;overflow-x:auto;white-space:pre-wrap;">'
            . htmlspecialchars($trace, ENT_QUOTES, 'UTF-8')
            . '</pre>' . PHP_EOL;
        echo '  </details>' . PHP_EOL;
    }

    echo '</div>' . PHP_EOL;
}
