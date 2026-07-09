<?php
/**
 * ==============================================================================
 * 📊 CueRCode - Activity Logging
 * ==============================================================================
 *
 * Path: _functions/fn_activity_log.php
 *
 * Provides functions for logging application activity events to the
 * tblActivityLog database table. This covers normal operational events
 * (not just errors), such as QR code generation, setting changes, API
 * calls, downloads, logins, and other user/system actions.
 *
 * Each log entry captures the activity type, detail, related entity,
 * user context, session, IP address, user agent, and optional JSON data.
 *
 * @package     CueRCode
 * @subpackage  Logging
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 1
 * @see         https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php
 * @see         https://dev.mysql.com/doc/refman/8.0/en/json.html
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
// 📝 logActivity() - Log an activity event
// ==============================================================================

/**
 * 📝 Log an activity event to the tblActivityLog database table
 *
 * Records a structured activity log entry with full request context.
 * This function is designed for normal operational events (as opposed to
 * logError() which is for errors and exceptions).
 *
 * Common activity types include:
 *   - qr_generated    : A QR code was generated
 *   - qr_downloaded   : A QR code was downloaded
 *   - setting_changed : A setting was modified
 *   - api_call        : An API request was made
 *   - login           : A user logged in
 *   - logout          : A user logged out
 *   - api_key_created : An API key was created
 *   - install         : Database was installed
 *
 * @param string      $type       The activity type identifier (e.g., 'qr_generated')
 * @param string      $detail     Human-readable description of the activity (default: '')
 * @param string|null $entityType Related entity type (e.g., 'qr_code', 'setting', 'api_key', 'user')
 * @param int|null    $entityID   ID of the related entity in its respective table
 * @param array       $extra      Additional context data stored as JSON in additionalData column.
 *                                Can contain any key-value pairs relevant to the activity.
 *
 * @return void
 *
 * @see https://www.php.net/manual/en/reserved.variables.server.php
 * @see https://www.php.net/manual/en/function.session-id.php
 * @see https://www.php.net/manual/en/function.json-encode.php
 *
 * @example
 *   // Log a QR code generation event
 *   logActivity(
 *       'qr_generated',
 *       'Generated PNG QR code for URL: https://example.com',
 *       'qr_code',
 *       42,
 *       ['format' => 'png', 'size' => 800, 'type' => 'url']
 *   );
 *
 *   // Log a setting change
 *   logActivity(
 *       'setting_changed',
 *       'Changed site_name from "Old Name" to "New Name"',
 *       'setting',
 *       15,
 *       ['key' => 'site_name', 'old_value' => 'Old Name', 'new_value' => 'New Name']
 *   );
 *
 *   // Log a simple event
 *   logActivity('api_call', 'GET /api/v1/generate');
 */
function logActivity(
    string  $type,
    string  $detail = '',
    ?string $entityType = null,
    ?int    $entityID = null,
    array   $extra = []
): void {
    // ==================================================================
    // 📋 Gather request context
    // @see https://www.php.net/manual/en/reserved.variables.server.php
    // ==================================================================

    // 👤 User ID from session (if available)
    // In Phase 1, this will typically be null (anonymous users).
    // In Phase 2+, this will be set after authentication.
    if (isset($_SESSION['userID'])) {
        $userID = $_SESSION['userID'];
    } else {
        $userID = null;
    }

    // 🎫 Session ID (if a session is active)
    // @see https://www.php.net/manual/en/function.session-id.php
    // @see https://www.php.net/manual/en/function.session-status.php
    if (session_status() === PHP_SESSION_ACTIVE) {
        $sessionID = session_id();
    } else {
        $sessionID = null;
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

    // 🌐 Build the full request URL
    // @see https://www.php.net/manual/en/reserved.variables.server.php
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

    // 📦 Encode additional data as JSON (or null if empty)
    // @see https://www.php.net/manual/en/function.json-encode.php
    if (!empty($extra)) {
        $additionalDataJSON = json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } else {
        $additionalDataJSON = null;
    }

    // ==================================================================
    // 🗄️ Insert into tblActivityLog
    // Uses dbQuery() prepared statement from db_connect.php.
    // @see https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php
    // ==================================================================
    try {
        // ✅ Verify dbQuery() is available
        if (!function_exists('dbQuery')) {
            // ❌ Database not available - fall back to error_log
            // @see https://www.php.net/manual/en/function.error-log.php
            if ($entityType !== null) {
                $entityTypeStr = $entityType;
            } else {
                $entityTypeStr = 'none';
            }
            if ($entityID !== null) {
                $entityIDStr = $entityID;
            } else {
                $entityIDStr = 'none';
            }
            error_log(
                'CueRCode Activity [' . $type . ']: ' . $detail
                . ' | Entity: ' . $entityTypeStr . ':' . $entityIDStr
            );
            return;
        }

        $stmt = dbQuery(
            "INSERT INTO `tblActivityLog` ("
            . "`activityType`, `activityDetail`, `entityType`, `entityID`, "
            . "`userID`, `sessionID`, `requestIP`, `userAgent`, `requestURL`, "
            . "`additionalData`"
            . ") VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            // 🔗 bind_param type string - one letter per bound parameter, in order.
            // VERIFIED bind mapping (2026-06-28, B-020 fix):
            //   pos  type  param           PHP type   column type
            //   ---  ----  --------------  ---------  -------------------------
            //    1    s    activityType    string     VARCHAR(100)
            //    2    s    activityDetail  string     TEXT
            //    3    s    entityType      string|null VARCHAR(50)
            //    4    i    entityID        int|null   BIGINT UNSIGNED
            //    5    i    userID          int|null   INT UNSIGNED
            //    6    s    sessionID       string|null VARCHAR(128)  <-- was wrongly 'i'
            //    7    s    requestIP       string     VARCHAR(45)
            //    8    s    userAgent       string     VARCHAR(500)
            //    9    s    requestURL      string     VARCHAR(2048)
            //   10    s    additionalData  string|null JSON
            // 🐛 B-020 fix: sessionID (pos 6) is a non-numeric session-id string
            //    (e.g. from session_id()); the previous 'sssiiissss' bound it as an
            //    integer, coercing it to 0 and corrupting the stored value. The
            //    correct type string is 'sssiisssss'.
            'sssiisssss',
            [
                mb_substr($type, 0, 100),            // activityType (VARCHAR 100)
                $detail,                              // activityDetail (TEXT)
                $entityType,                          // entityType (VARCHAR 50, nullable)
                $entityID,                            // entityID (BIGINT UNSIGNED, nullable)
                $userID,                              // userID (INT UNSIGNED, nullable)
                $sessionID,                           // sessionID (VARCHAR 128, nullable)
                mb_substr($requestIP, 0, 45),         // requestIP (VARCHAR 45)
                mb_substr($userAgent, 0, 500),        // userAgent (VARCHAR 500)
                mb_substr($requestURL, 0, 2048),      // requestURL (VARCHAR 2048)
                $additionalDataJSON,                  // additionalData (JSON, nullable)
            ]
        );
        $stmt->close();
    } catch (\Throwable $e) {
        // ❌ Database insert failed - fall back to error_log
        // We use error_log() directly instead of logError() to avoid potential
        // circular dependency issues (logError may also call logActivity in some setups).
        // @see https://www.php.net/manual/en/function.error-log.php
        error_log(
            'CueRCode Activity Log FAILED [' . $type . ']: ' . $detail
            . ' | DB Error: ' . $e->getMessage()
        );
    }
}

// ==============================================================================
// 📋 getRecentActivity() - Fetch recent activity log entries
// ==============================================================================

/**
 * 📋 Fetch recent activity log entries from the database
 *
 * Retrieves the most recent activity log entries ordered by timestamp
 * descending (newest first). Useful for admin dashboards, audit trails,
 * and monitoring application usage.
 *
 * Each returned row contains all columns from tblActivityLog, with the
 * additionalData JSON column decoded into a PHP array.
 *
 * @param int $limit Maximum number of entries to retrieve (default: 50, max: 1000)
 *
 * @return array Array of activity log entries (associative arrays), or empty array on failure
 *
 * @see https://www.php.net/manual/en/function.json-decode.php
 * @see https://www.php.net/manual/en/function.min.php
 *
 * @example
 *   // Get the 10 most recent activities
 *   $recent = getRecentActivity(10);
 *   foreach ($recent as $activity) {
 *       echo $activity['activityType'] . ': ' . $activity['activityDetail'] . PHP_EOL;
 *   }
 *
 *   // Get default (50) recent activities
 *   $activities = getRecentActivity();
 */
function getRecentActivity(int $limit = 50): array
{
    // 📌 Clamp the limit to a reasonable maximum to prevent excessive memory usage
    // @see https://www.php.net/manual/en/function.min.php
    // @see https://www.php.net/manual/en/function.max.php
    $limit = max(1, min($limit, 1000));

    try {
        // ✅ Verify dbQuery() is available
        if (!function_exists('dbQuery')) {
            return [];
        }

        // 🗄️ Query the most recent activity entries
        // @see https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php
        $stmt = dbQuery(
            "SELECT "
            . "`activityID`, `activityType`, `activityDetail`, `entityType`, `entityID`, "
            . "`userID`, `sessionID`, `requestIP`, `userAgent`, `requestURL`, "
            . "`additionalData`, `createdAt` "
            . "FROM `tblActivityLog` "
            . "ORDER BY `createdAt` DESC "
            . "LIMIT ?",
            'i',
            [$limit]
        );

        $result     = $stmt->get_result();
        $activities = [];

        // 🔄 Process each row
        while ($row = $result->fetch_assoc()) {
            // 📦 Decode the additionalData JSON column into a PHP array
            // @see https://www.php.net/manual/en/function.json-decode.php
            if ($row['additionalData'] !== null && $row['additionalData'] !== '') {
                $decodedData = json_decode($row['additionalData'], true);
                if ($decodedData !== null) {
                    $row['additionalData'] = $decodedData;
                } else {
                    $row['additionalData'] = [];
                }
            } else {
                $row['additionalData'] = [];
            }

            $activities[] = $row;
        }

        $stmt->close();

        return $activities;
    } catch (\Throwable $e) {
        // ❌ Query failed - log and return empty array
        if (function_exists('logError')) {
            logError('error', 'getRecentActivity failed', $e->getMessage());
        }
        return [];
    }
}
