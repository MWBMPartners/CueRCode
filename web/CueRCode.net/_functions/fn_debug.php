<?php
/**
 * ==============================================================================
 * 🐛 CueRCode - Debug Mode Utilities
 * ==============================================================================
 *
 * Path: _functions/fn_debug.php
 *
 * Provides functions for checking whether debug mode is active and for
 * outputting formatted debug information during development and
 * troubleshooting.
 *
 * Debug mode is activated when BOTH conditions are met:
 *   1. The `debug_mode` setting in tblSettings (settingGroup 'security')
 *      is set to '1' (truthy).
 *   2. The URL query parameter `?debug=true` is present in the request.
 *
 * This dual-check ensures that debug output is never accidentally shown
 * in production, even if someone appends ?debug=true to the URL.
 *
 * @package     CueRCode
 * @subpackage  Debugging
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 1
 * @see         https://www.php.net/manual/en/reserved.variables.get.php
 * @see         https://www.php.net/manual/en/function.microtime.php
 * @see         https://www.php.net/manual/en/function.var-export.php
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
// 🐛 isDebugMode() - Check if debug mode is active
// ==============================================================================

/**
 * 🐛 Check whether debug mode is currently active
 *
 * Debug mode requires BOTH conditions to be true:
 *   1. The `debug_mode` setting in tblSettings is truthy ('1').
 *   2. The URL contains the query parameter `?debug=true`.
 *
 * The result is cached in a static variable so the database is only
 * queried once per request regardless of how many times this function
 * is called.
 *
 * If the getConfig() function is not available (e.g., fn_config.php or
 * db_connect.php not loaded yet), this function falls back to checking
 * only the $_GET parameter with a direct database query attempt.
 *
 * @return bool True if debug mode is active, false otherwise
 *
 * @see https://www.php.net/manual/en/reserved.variables.get.php
 * @see https://www.php.net/manual/en/language.variables.scope.php#language.variables.scope.static
 *
 * @example
 *   if (isDebugMode()) {
 *       debugOutput($someVariable, 'Debug Data');
 *   }
 */
function isDebugMode(): bool
{
    // 📌 Static cache - only evaluate once per request
    // @see https://www.php.net/manual/en/language.variables.scope.php#language.variables.scope.static
    static $debugMode = null;

    if ($debugMode !== null) {
        return $debugMode;
    }

    // 🔍 Condition 1: Check the URL query parameter
    // The parameter must be exactly 'true' (case-sensitive string match).
    // @see https://www.php.net/manual/en/reserved.variables.get.php
    if (isset($_GET['debug'])) {
        $debugParam = $_GET['debug'];
    } else {
        $debugParam = '';
    }
    $hasDebugParam = ($debugParam === 'true');

    // 🔍 If the URL parameter is not present, debug mode is off regardless
    if (!$hasDebugParam) {
        $debugMode = false;
        return false;
    }

    // 🔍 Condition 2: Check the database setting via getConfig()
    // The 'debug_mode' setting in the 'security' group must be truthy.
    // @see fn_config.php - getConfig()
    if (function_exists('getConfig')) {
        try {
            $dbDebugMode = getConfig('debug_mode', false);
            $debugMode   = (bool) $dbDebugMode;
            return $debugMode;
        } catch (\Throwable $e) {
            // ❌ Config lookup failed - default to false for safety
            $debugMode = false;
            return false;
        }
    }

    // ⚠️ Fallback: getConfig() not available (fn_config.php not loaded)
    // Attempt a direct database query if dbQuery() is available.
    if (function_exists('dbQuery')) {
        try {
            $stmt = dbQuery(
                "SELECT `settingValue` "
                . "FROM `tblSettings` "
                . "WHERE `settingKey` = ? "
                . "LIMIT 1",
                's',
                ['debug_mode']
            );

            $result = $stmt->get_result();
            $row    = $result->fetch_assoc();
            $stmt->close();

            if ($row !== null) {
                // 🔄 Interpret '1', 'true', 'yes' as truthy
                // @see https://www.php.net/manual/en/function.filter-var.php
                $debugMode = filter_var($row['settingValue'], FILTER_VALIDATE_BOOLEAN);
                return $debugMode;
            }
        } catch (\Throwable $e) {
            // ❌ Database query failed - default to false
        }
    }

    // 🔒 Default: debug mode is off if we cannot verify the database setting
    $debugMode = false;
    return false;
}

// ==============================================================================
// 📋 debugOutput() - Output formatted debug data
// ==============================================================================

/**
 * 📋 Output formatted debug data if debug mode is active
 *
 * Displays the provided data in a styled HTML block with an optional label.
 * Uses var_export() for structured output of arrays, objects, and other types.
 *
 * This function does nothing if isDebugMode() returns false, making it safe
 * to leave debug calls in production code without side effects.
 *
 * @param mixed  $data  The data to display (string, array, object, int, bool, etc.)
 * @param string $label Optional label displayed above the debug output
 *
 * @return void
 *
 * @see https://www.php.net/manual/en/function.var-export.php
 * @see https://www.php.net/manual/en/function.htmlspecialchars.php
 * @see https://www.php.net/manual/en/function.gettype.php
 *
 * @example
 *   debugOutput($configArray, 'Configuration Settings');
 *   debugOutput($_SERVER, 'Server Variables');
 *   debugOutput($sqlResult, 'Query Result');
 */
function debugOutput(mixed $data, string $label = ''): void
{
    // 🚫 Do nothing if debug mode is not active
    if (!isDebugMode()) {
        return;
    }

    // 📌 Determine the data type for display
    // @see https://www.php.net/manual/en/function.gettype.php
    $dataType = gettype($data);

    // 🔄 For objects, include the class name
    if ($dataType === 'object') {
        $dataType = 'object (' . get_class($data) . ')';
    }

    // 📋 Convert data to a human-readable string
    // @see https://www.php.net/manual/en/function.var-export.php
    if (is_string($data)) {
        $formatted = $data;
    } else {
        $formatted = var_export($data, true);
    }

    // 📌 Calculate a unique ID for collapsible sections
    static $debugCounter = 0;
    $debugCounter++;
    $blockID = 'cuercode-debug-' . $debugCounter;

    // 🖥️ Output styled debug block
    // All output is HTML-escaped to prevent XSS.
    // @see https://www.php.net/manual/en/function.htmlspecialchars.php
    echo '<div id="' . $blockID . '" style="margin:10px;padding:15px;border-left:5px solid #17a2b8;'
        . 'background:#f0f9ff;font-family:monospace;font-size:13px;line-height:1.5;'
        . 'border-radius:4px;overflow-x:auto;">' . PHP_EOL;

    // 🏷️ Label row
    echo '  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">' . PHP_EOL;

    if ($label !== '') {
        echo '    <span style="display:inline-block;padding:2px 8px;background:#17a2b8;color:#fff;'
            . 'border-radius:3px;font-weight:bold;">DEBUG: '
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
            . '</span>' . PHP_EOL;
    } else {
        echo '    <span style="display:inline-block;padding:2px 8px;background:#17a2b8;color:#fff;'
            . 'border-radius:3px;font-weight:bold;">DEBUG</span>' . PHP_EOL;
    }

    echo '    <span style="color:#6c757d;font-size:11px;">Type: '
        . htmlspecialchars($dataType, ENT_QUOTES, 'UTF-8')
        . ' | ' . date('H:i:s.') . substr((string) microtime(true), -3)
        . '</span>' . PHP_EOL;

    echo '  </div>' . PHP_EOL;

    // 📋 Data output
    echo '  <pre style="margin:0;padding:10px;background:#e8f4f8;border-radius:3px;'
        . 'overflow-x:auto;white-space:pre-wrap;word-break:break-word;max-height:500px;overflow-y:auto;">'
        . htmlspecialchars($formatted, ENT_QUOTES, 'UTF-8')
        . '</pre>' . PHP_EOL;

    echo '</div>' . PHP_EOL;
}

// ==============================================================================
// ⏱️ debugTimer() - Measure elapsed time since request start
// ==============================================================================

/**
 * ⏱️ Get the elapsed time since the request started
 *
 * Returns the number of seconds (with microsecond precision) elapsed
 * since the PHP request began, as measured from $_SERVER['REQUEST_TIME_FLOAT'].
 *
 * Optionally outputs a labelled timer value if debug mode is active.
 *
 * @param string $label Optional label - if provided and debug mode is active,
 *                      the elapsed time is displayed on-page with this label
 *
 * @return float Elapsed time in seconds with microsecond precision
 *
 * @see https://www.php.net/manual/en/reserved.variables.server.php
 * @see https://www.php.net/manual/en/function.microtime.php
 *
 * @example
 *   // Get elapsed time silently
 *   $elapsed = debugTimer();
 *
 *   // Display a checkpoint timer in debug mode
 *   debugTimer('After database query');
 *   // Output: "TIMER [After database query]: 0.0234s"
 *
 *   // Use for performance measurement
 *   $start = debugTimer();
 *   // ... expensive operation ...
 *   $end = debugTimer();
 *   debugOutput($end - $start, 'Operation Duration (seconds)');
 */
function debugTimer(string $label = ''): float
{
    // 📌 Use REQUEST_TIME_FLOAT for the most accurate request start time
    // This is set by PHP at the very start of request processing.
    // @see https://www.php.net/manual/en/reserved.variables.server.php
    if (isset($_SERVER['REQUEST_TIME_FLOAT'])) {
        $requestStart = $_SERVER['REQUEST_TIME_FLOAT'];
    } else {
        $requestStart = microtime(true);
    }

    // ⏱️ Calculate elapsed time
    // @see https://www.php.net/manual/en/function.microtime.php
    $elapsed = microtime(true) - $requestStart;

    // 🖥️ Display timer output if a label is provided and debug mode is active
    if ($label !== '' && isDebugMode()) {
        // 📌 Format elapsed time to 4 decimal places (microsecond precision)
        // @see https://www.php.net/manual/en/function.number-format.php
        $formattedTime = number_format($elapsed, 4, '.', '');

        echo '<div style="margin:10px;padding:8px 15px;border-left:5px solid #28a745;'
            . 'background:#f0fff4;font-family:monospace;font-size:13px;'
            . 'border-radius:4px;display:flex;align-items:center;gap:10px;">' . PHP_EOL;

        echo '  <span style="display:inline-block;padding:2px 8px;background:#28a745;color:#fff;'
            . 'border-radius:3px;font-weight:bold;font-size:11px;">TIMER</span>' . PHP_EOL;

        echo '  <span style="color:#212529;">'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
            . ': <strong>' . $formattedTime . 's</strong></span>' . PHP_EOL;

        echo '</div>' . PHP_EOL;
    }

    return $elapsed;
}
