<?php
/**
 * ==============================================================================
 * 🔐 CueRCode - API Authentication & Rate Limiting
 * ==============================================================================
 *
 * Path: _functions/fn_api_auth.php
 *
 * Provides functions for authenticating REST API requests and enforcing
 * rate limits. Supports two authentication methods:
 *
 *   1. API Key Authentication (X-API-Key header)
 *      - For external consumers (third-party integrations, mobile apps)
 *      - Keys are hashed with HMAC-SHA256 and stored in tblAPIKeys
 *      - Per-key rate limits defined in tblAPIKeys columns
 *      - Per-key SCOPE enforcement (Epic E7 stage 1, #54): a key's
 *        tblAPIKeys.permissions JSON is decoded by validateAPIKey() into
 *        $result['scopes'] and surfaced on authenticateAPIRequest()'s
 *        returned array; the router (public_html/api/v1/index.php) checks a
 *        route's 'requiredScope' against it via apiKeyRequestHasScope()
 *        below, replying 403 INSUFFICIENT_SCOPE when absent. Session/CSRF
 *        authentication is NEVER scope-limited (full user rights) - scope
 *        enforcement applies ONLY to the 'api_key' auth method.
 *
 *   2. Session-Based CSRF Authentication (X-CSRF-Token header)
 *      - For the web UI making AJAX requests
 *      - Uses the existing CSRF token validation from fn_csrf.php
 *      - Rate limits use global config settings
 *
 * Anonymous (unauthenticated) requests are allowed for public endpoints
 * (health check, types, formats) with stricter rate limits.
 *
 * Rate limiting is tracked in tblAPIRateLog with per-minute and per-day
 * windows. Entries older than 24 hours are periodically cleaned up.
 *
 * @package     CueRCode
 * @subpackage  API
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2
 * @see         https://www.php.net/manual/en/function.hash-hmac.php
 * @see         https://www.php.net/manual/en/function.hash-equals.php
 * @see         https://www.php.net/manual/en/function.time.php
 * @see         https://cheatsheetseries.owasp.org/cheatsheets/REST_Security_Cheat_Sheet.html
 * @see         https://developer.mozilla.org/en-US/docs/Web/HTTP/Status/429
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
// 📌 API Authentication Constants
// ==============================================================================

/**
 * @var string Expected prefix for API keys
 * All valid API keys start with this prefix followed by 40 hex characters.
 * @see fn_encryption.php - generateAPIKey()
 */
if (!defined('CUERCODE_API_KEY_PREFIX')) {
    define('CUERCODE_API_KEY_PREFIX', 'cuercode_');
}

/**
 * @var int Expected length of the full API key (prefix + 40 hex chars)
 * Format: cuercode_ (9 chars) + 40 hex chars = 49 total
 * @see fn_encryption.php - generateAPIKey() -> 'cuercode_' . bin2hex(random_bytes(20))
 */
if (!defined('CUERCODE_API_KEY_LENGTH')) {
    define('CUERCODE_API_KEY_LENGTH', 49);
}

/**
 * @var int Default rate limit per minute for anonymous requests
 * Can be overridden via the 'api_rate_limit_anonymous_per_min' setting in tblSettings.
 */
if (!defined('CUERCODE_API_ANON_RATE_PER_MIN')) {
    define('CUERCODE_API_ANON_RATE_PER_MIN', 10);
}

/**
 * @var int Default rate limit per day for anonymous requests
 * Can be overridden via the 'api_rate_limit_anonymous_per_day' setting in tblSettings.
 */
if (!defined('CUERCODE_API_ANON_RATE_PER_DAY')) {
    define('CUERCODE_API_ANON_RATE_PER_DAY', 100);
}

// ==============================================================================
// 🔑 authenticateAPIRequest() - Authenticate an incoming API request
// ==============================================================================

/**
 * 🔑 Authenticate an incoming API request
 *
 * Checks for authentication credentials in the following priority order:
 *   1. X-API-Key header (API key authentication for external consumers)
 *   2. X-CSRF-Token header (session-based CSRF for web UI AJAX calls)
 *   3. Anonymous (no authentication - allowed for public endpoints)
 *
 * Returns an associative array describing the authentication result:
 *   - 'authenticated' (bool)       Whether the request is authenticated
 *   - 'method'        (string)     Authentication method: 'api_key', 'csrf', or 'anonymous'
 *   - 'apiKeyId'      (int|null)   The tblAPIKeys.apiKeyID if API key auth, null otherwise
 *   - 'rateLimits'    (array)      Applicable rate limits ['perMin' => int, 'perDay' => int]
 *
 * @return array Authentication result array
 *
 * @see https://www.php.net/manual/en/reserved.variables.server.php
 * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers
 *
 * @example
 *   $auth = authenticateAPIRequest();
 *   if (!$auth['authenticated'] && $requiresAuth) {
 *       errorResponse('Authentication required.', 401, 'AUTH_REQUIRED');
 *   }
 */
function authenticateAPIRequest(): array
{
    // 📌 Default result: unauthenticated anonymous request
    if (function_exists('getConfig')) {
        $anonPerMin = (int) getConfig('api_rate_limit_anonymous_per_min', CUERCODE_API_ANON_RATE_PER_MIN);
    } else {
        $anonPerMin = CUERCODE_API_ANON_RATE_PER_MIN;
    }
    if (function_exists('getConfig')) {
        $anonPerDay = (int) getConfig('api_rate_limit_anonymous_per_day', CUERCODE_API_ANON_RATE_PER_DAY);
    } else {
        $anonPerDay = CUERCODE_API_ANON_RATE_PER_DAY;
    }
    $result = [
        'authenticated' => false,
        'method'        => 'anonymous',
        'apiKeyId'      => null,
        'rateLimits'    => [
            'perMin' => $anonPerMin,
            'perDay' => $anonPerDay,
        ],
        // 🔒 Epic E7 stage 1 (#54): the AUTHENTICATED key's granted scopes
        // (decoded tblAPIKeys.permissions). Always an array (never null) so
        // apiKeyRequestHasScope() below never has to null-check. Irrelevant
        // for 'csrf'/'anonymous' methods - scope enforcement only reads this
        // when $result['method'] === 'api_key' (see the router's Scope
        // Enforcement section).
        'scopes'        => [],
    ];

    // ==================================================================
    // 📌 Priority 1: Check for X-API-Key header
    // PHP converts headers to uppercase with HTTP_ prefix in $_SERVER.
    // @see https://www.php.net/manual/en/reserved.variables.server.php
    // ==================================================================
    if (isset($_SERVER['HTTP_X_API_KEY'])) {
        $apiKey = $_SERVER['HTTP_X_API_KEY'];
    } else {
        $apiKey = '';
    }

    if ($apiKey !== '') {
        $keyValidation = validateAPIKey($apiKey);

        if ($keyValidation['valid']) {
            $result['authenticated'] = true;
            $result['method']        = 'api_key';
            $result['apiKeyId']      = $keyValidation['apiKeyId'];
            $result['rateLimits']    = [
                'perMin' => $keyValidation['rateLimitPerMin'],
                'perDay' => $keyValidation['rateLimitPerDay'],
            ];
            // 🔒 Epic E7 stage 1 (#54): carry the key's decoded scopes
            // through to the router's scope-enforcement check.
            $result['scopes']        = $keyValidation['scopes'];

            return $result;
        }

        // ❌ API key was provided but is invalid
        // Return immediately with authentication failure rather than
        // falling through to CSRF or anonymous.
        $result['authenticated'] = false;
        $result['method']        = 'api_key';

        return $result;
    }

    // ==================================================================
    // 📌 Priority 2: Check for CSRF token (session-based web UI auth)
    // The CSRF token comes from the X-CSRF-Token header ONLY. A
    // $_POST['csrf_token'] fallback (for classic form submissions) was
    // deliberately removed here (dead-code cleanup, E3 stage 1 security
    // review): the router (public_html/api/v1/index.php) mandates
    // Content-Type: application/json on every non-empty POST body and
    // rejects anything else with 415 BEFORE this function ever runs
    // (F-009) - PHP only auto-populates $_POST for
    // 'application/x-www-form-urlencoded'/'multipart/form-data' bodies, so
    // a real form POST can never reach here with a populated $_POST.
    // @see fn_csrf.php - validateCSRFToken()
    // ==================================================================
    if (isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'];
    } else {
        $csrfToken = '';
    }

    if ($csrfToken !== '' && function_exists('validateCSRFToken')) {
        if (validateCSRFToken($csrfToken)) {
            $result['authenticated'] = true;
            $result['method']        = 'csrf';
            if (function_exists('getConfig')) {
                $csrfPerMin = (int) getConfig('api_rate_limit_csrf_per_min', 30);
            } else {
                $csrfPerMin = 30;
            }
            if (function_exists('getConfig')) {
                $csrfPerDay = (int) getConfig('api_rate_limit_csrf_per_day', 1000);
            } else {
                $csrfPerDay = 1000;
            }
            $result['rateLimits']    = [
                'perMin' => $csrfPerMin,
                'perDay' => $csrfPerDay,
            ];

            return $result;
        }

        // ❌ CSRF token was provided but is invalid/expired
        $result['authenticated'] = false;
        $result['method']        = 'csrf';

        return $result;
    }

    // ==================================================================
    // 📌 Priority 3: Anonymous request
    // No credentials provided. The caller must determine whether
    // the endpoint allows anonymous access.
    // ==================================================================
    return $result;
}

// ==============================================================================
// 🔒 apiKeyRequestHasScope() - Scope enforcement check (Epic E7 stage 1, #54)
// ==============================================================================

/**
 * 🔒 Does an authenticated request's API key carry a required scope?
 *
 * Called by the router (public_html/api/v1/index.php) ONLY when
 * `$auth['method'] === 'api_key'` AND the matched route defines a
 * non-null 'requiredScope' - a session/CSRF-authenticated request is NEVER
 * scope-limited (full user rights) and never reaches this check at all.
 *
 * Fail-closed by construction: an $auth array missing/malformed 'scopes'
 * (should never happen via authenticateAPIRequest(), but defensive
 * regardless) is treated as "no scopes granted", not "everything allowed".
 *
 * @param array  $auth           The router's authentication context
 *                                (authenticateAPIRequest()'s return value).
 * @param string $requiredScope  The scope string the matched route requires
 *                                (e.g. 'generate', 'qrcodes:write').
 *
 * @return bool True if the key's granted scopes include $requiredScope.
 *
 * @see fn_api_keys.php - CUERCODE_API_KEY_SCOPE_ALLOWLIST (the scope taxonomy)
 * @see public_html/api/v1/index.php - the router's Scope Enforcement section
 *
 * @example
 *   $auth = authenticateAPIRequest();
 *   if ($auth['method'] === 'api_key' && !apiKeyRequestHasScope($auth, 'generate')) {
 *       errorResponse('Missing required scope: generate.', 403, 'INSUFFICIENT_SCOPE');
 *   }
 */
function apiKeyRequestHasScope(array $auth, string $requiredScope): bool
{
    if (!isset($auth['scopes']) || !is_array($auth['scopes'])) {
        return false;
    }

    return in_array($requiredScope, $auth['scopes'], true);
}

// ==============================================================================
// 🔐 validateAPIKey() - Validate an API key against the database
// ==============================================================================

/**
 * 🔐 Validate an API key against the tblAPIKeys database table
 *
 * Performs the following validation steps:
 *   1. Format check: must be 'cuercode_' + 40 hex characters (49 total)
 *   2. Hash the key with HMAC-SHA256 using hashAPIKey()
 *   3. Look up the hash in tblAPIKeys
 *   4. Check that isActive = 1
 *   5. Update lastUsedAt timestamp on successful validation
 *
 * @param string $apiKey The raw API key from the X-API-Key header
 *
 * @return array Validation result:
 *   - 'valid'           (bool)  Whether the key is valid, active, and unexpired
 *   - 'apiKeyId'        (int)   The tblAPIKeys.apiKeyID (0 if invalid)
 *   - 'rateLimitPerMin' (int)   Per-minute rate limit for this key
 *   - 'rateLimitPerDay' (int)   Per-day rate limit for this key
 *   - 'scopes'          (array) The key's decoded tblAPIKeys.permissions scope
 *                                list (Epic E7 stage 1, #54) - always an array,
 *                                empty when invalid/malformed/absent
 *
 * @see fn_encryption.php - hashAPIKey()
 * @see https://www.php.net/manual/en/function.preg-match.php
 * @see https://www.php.net/manual/en/function.hash-equals.php
 *
 * @example
 *   $result = validateAPIKey('cuercode_a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2');
 *   if ($result['valid']) {
 *       echo 'API key is valid. Key ID: ' . $result['apiKeyId'];
 *   }
 */
function validateAPIKey(string $apiKey): array
{
    // 📌 Default result: invalid key
    $result = [
        'valid'           => false,
        'apiKeyId'        => 0,
        'rateLimitPerMin' => CUERCODE_API_ANON_RATE_PER_MIN,
        'rateLimitPerDay' => CUERCODE_API_ANON_RATE_PER_DAY,
        'scopes'          => [],
    ];

    // ==================================================================
    // 📌 Step 1: Validate key format
    // Expected format: cuercode_ + 40 hexadecimal characters
    // @see https://www.php.net/manual/en/function.preg-match.php
    // ==================================================================
    if (strlen($apiKey) !== CUERCODE_API_KEY_LENGTH) {
        return $result;
    }

    if (preg_match('/^cuercode_[0-9a-f]{40}$/', $apiKey) !== 1) {
        return $result;
    }

    // ==================================================================
    // 📌 Step 2: Hash the API key
    // The raw key is never stored in the database. We hash it with
    // HMAC-SHA256 and compare the hash against tblAPIKeys.apiKeyHash.
    // @see fn_encryption.php - hashAPIKey()
    // ==================================================================
    if (!function_exists('hashAPIKey')) {
        if (function_exists('logError')) {
            logError(
                'error',
                'API key validation failed',
                'hashAPIKey() function not available. Ensure fn_encryption.php is loaded.'
            );
        }
        return $result;
    }

    $keyHash = hashAPIKey($apiKey);

    // ==================================================================
    // 📌 Step 3: Look up the hash in tblAPIKeys
    // @see https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php
    // ==================================================================
    if (!function_exists('dbQuery')) {
        return $result;
    }

    try {
        $stmt = dbQuery(
            "SELECT `apiKeyID`, `apiKeyHash`, `rateLimitPerMin`, `rateLimitPerDay`, `isActive`, "
            . "`permissions`, `expiresAt` "
            . "FROM `tblAPIKeys` "
            . "WHERE `apiKeyHash` = ? "
            . "LIMIT 1",
            's',
            [$keyHash]
        );

        $queryResult = $stmt->get_result();
        $row         = $queryResult->fetch_assoc();
        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError(
                'error',
                'API key database lookup failed',
                $e->getMessage()
            );
        }
        return $result;
    }

    // ❌ Key hash not found in database
    if ($row === null) {
        return $result;
    }

    // ==================================================================
    // 📌 Step 4: Check that the key is active
    // ==================================================================
    if ((int) $row['isActive'] !== 1) {
        if (function_exists('logError')) {
            logError(
                'warning',
                'Inactive API key used',
                'API Key ID: ' . $row['apiKeyID'] . ' is inactive.'
            );
        }
        return $result;
    }

    // ==================================================================
    // 📌 Step 4b: Check that the key has not expired (tblAPIKeys.expiresAt,
    // NULL = never expires). Epic E7 stage 1 (#54) - previously UNENFORCED;
    // self-service creation does not yet expose an expiry input to callers,
    // but this closes the latent gap for any key an admin or future feature
    // sets expiresAt on (defence in depth - see SECURITY.md).
    // ==================================================================
    if ($row['expiresAt'] !== null) {
        $expiresAtTimestamp = strtotime((string) $row['expiresAt']);

        if ($expiresAtTimestamp !== false && $expiresAtTimestamp < time()) {
            if (function_exists('logError')) {
                logError(
                    'warning',
                    'Expired API key used',
                    'API Key ID: ' . $row['apiKeyID'] . ' expired at ' . $row['expiresAt'] . '.'
                );
            }
            return $result;
        }
    }

    // ==================================================================
    // 📌 Step 5: Timing-safe hash comparison
    // Even though we looked up by hash, we perform a timing-safe
    // comparison as an additional security measure.
    // @see https://www.php.net/manual/en/function.hash-equals.php
    // ==================================================================
    if (!hash_equals($row['apiKeyHash'], $keyHash)) {
        return $result;
    }

    // ==================================================================
    // 🔒 Step 5b: Decode the key's granted scopes (Epic E7 stage 1, #54).
    // A malformed/missing permissions value decodes to an empty scope list
    // (fail-closed - a key with no readable scopes can access nothing
    // scoped) rather than throwing or granting anything.
    // @see fn_api_keys.php - CUERCODE_API_KEY_SCOPE_ALLOWLIST, apiKeyRequestHasScope()
    // ==================================================================
    $decodedScopes = json_decode((string) $row['permissions'], true);
    if (!is_array($decodedScopes)) {
        $decodedScopes = [];
    }
    $normalizedScopes = [];
    foreach ($decodedScopes as $scopeValue) {
        if (is_string($scopeValue)) {
            $normalizedScopes[] = $scopeValue;
        }
    }

    // ==================================================================
    // ✅ Key is valid - populate result and update lastUsedAt
    // ==================================================================
    $result['valid']           = true;
    $result['apiKeyId']        = (int) $row['apiKeyID'];
    $result['rateLimitPerMin'] = (int) $row['rateLimitPerMin'];
    $result['rateLimitPerDay'] = (int) $row['rateLimitPerDay'];
    $result['scopes']          = $normalizedScopes;

    // 📌 Update the lastUsedAt timestamp
    // @see https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php
    try {
        $updateStmt = dbQuery(
            "UPDATE `tblAPIKeys` SET `lastUsedAt` = NOW() WHERE `apiKeyID` = ?",
            'i',
            [$result['apiKeyId']]
        );
        $updateStmt->close();
    } catch (\Throwable $e) {
        // ⚠️ Non-critical: log but don't fail the authentication
        if (function_exists('logError')) {
            logError(
                'warning',
                'Failed to update API key lastUsedAt',
                'Key ID: ' . $result['apiKeyId'] . ' | ' . $e->getMessage()
            );
        }
    }

    return $result;
}

// ==============================================================================
// ⏱️ checkRateLimit() - Check rate limits for an API request
// ==============================================================================

/**
 * ⏱️ Check whether an API request exceeds rate limits
 *
 * Counts requests in tblAPIRateLog for the given API key ID (or IP address
 * for anonymous requests) within the per-minute and per-day windows.
 *
 * Returns an array indicating whether the rate limit is exceeded and
 * the remaining quota for both windows.
 *
 * @param int|null $apiKeyId   The tblAPIKeys.apiKeyID (null for anonymous/CSRF)
 * @param string   $ipAddress  The client IP address
 * @param array    $limits     Rate limits: ['perMin' => int, 'perDay' => int]
 *
 * @return array Rate limit check result:
 *   - 'allowed'       (bool) Whether the request is within limits
 *   - 'retryAfter'    (int)  Seconds until the limit resets (0 if allowed)
 *   - 'remainingMin'  (int)  Remaining requests in the current minute window
 *   - 'remainingDay'  (int)  Remaining requests in the current day window
 *   - 'limitPerMin'   (int)  The per-minute limit that was checked
 *   - 'limitPerDay'   (int)  The per-day limit that was checked
 *
 * @see https://www.php.net/manual/en/function.time.php
 * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Status/429
 * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Retry-After
 *
 * @example
 *   $rateCheck = checkRateLimit($auth['apiKeyId'], $clientIP, $auth['rateLimits']);
 *   if (!$rateCheck['allowed']) {
 *       header('Retry-After: ' . $rateCheck['retryAfter']);
 *       errorResponse('Rate limit exceeded.', 429, 'RATE_LIMIT_EXCEEDED');
 *   }
 */
function checkRateLimit(?int $apiKeyId, string $ipAddress, array $limits): array
{
    // 📌 Default result: allowed
    if (isset($limits['perMin'])) {
        $limitsPerMin = $limits['perMin'];
    } else {
        $limitsPerMin = CUERCODE_API_ANON_RATE_PER_MIN;
    }
    if (isset($limits['perDay'])) {
        $limitsPerDay = $limits['perDay'];
    } else {
        $limitsPerDay = CUERCODE_API_ANON_RATE_PER_DAY;
    }
    $result = [
        'allowed'      => true,
        'retryAfter'   => 0,
        'remainingMin' => $limitsPerMin,
        'remainingDay' => $limitsPerDay,
        'limitPerMin'  => $limitsPerMin,
        'limitPerDay'  => $limitsPerDay,
    ];

    if (!function_exists('dbQuery')) {
        return $result;
    }

    $perMin = $result['limitPerMin'];
    $perDay = $result['limitPerDay'];

    // ==================================================================
    // 📌 Count requests in the per-minute window
    // We count requests from the last 60 seconds.
    // @see https://www.php.net/manual/en/function.time.php
    // ==================================================================
    try {
        if ($apiKeyId !== null) {
            // 📌 Authenticated request: count by API key ID
            $minuteStmt = dbQuery(
                "SELECT COUNT(*) AS `requestCount` "
                . "FROM `tblAPIRateLog` "
                . "WHERE `apiKeyID` = ? "
                . "AND `requestedAt` >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)",
                'i',
                [$apiKeyId]
            );
        } else {
            // 📌 Anonymous/CSRF request: count by IP address
            $minuteStmt = dbQuery(
                "SELECT COUNT(*) AS `requestCount` "
                . "FROM `tblAPIRateLog` "
                . "WHERE `apiKeyID` IS NULL "
                . "AND `requestIP` = ? "
                . "AND `requestedAt` >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)",
                's',
                [$ipAddress]
            );
        }

        $minuteResult = $minuteStmt->get_result();
        $minuteRow    = $minuteResult->fetch_assoc();
        $minuteStmt->close();

        if (isset($minuteRow['requestCount'])) {
            $minuteCount = (int) $minuteRow['requestCount'];
        } else {
            $minuteCount = 0;
        }
        $result['remainingMin'] = max(0, $perMin - $minuteCount);

        // ❌ Per-minute limit exceeded
        if ($minuteCount >= $perMin) {
            $result['allowed']    = false;
            $result['retryAfter'] = 60; // Retry after 60 seconds
            return $result;
        }
    } catch (\Throwable $e) {
        // ⚠️ Rate limit check failed - allow the request
        // Failing open is acceptable here; the alternative (failing closed)
        // would deny service due to a database issue.
        if (function_exists('logError')) {
            logError('warning', 'Rate limit minute check failed', $e->getMessage());
        }
        return $result;
    }

    // ==================================================================
    // 📌 Count requests in the per-day window
    // We count requests from the last 24 hours.
    // ==================================================================
    try {
        if ($apiKeyId !== null) {
            $dayStmt = dbQuery(
                "SELECT COUNT(*) AS `requestCount` "
                . "FROM `tblAPIRateLog` "
                . "WHERE `apiKeyID` = ? "
                . "AND `requestedAt` >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
                'i',
                [$apiKeyId]
            );
        } else {
            $dayStmt = dbQuery(
                "SELECT COUNT(*) AS `requestCount` "
                . "FROM `tblAPIRateLog` "
                . "WHERE `apiKeyID` IS NULL "
                . "AND `requestIP` = ? "
                . "AND `requestedAt` >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
                's',
                [$ipAddress]
            );
        }

        $dayResult = $dayStmt->get_result();
        $dayRow    = $dayResult->fetch_assoc();
        $dayStmt->close();

        if (isset($dayRow['requestCount'])) {
            $dayCount = (int) $dayRow['requestCount'];
        } else {
            $dayCount = 0;
        }
        $result['remainingDay'] = max(0, $perDay - $dayCount);

        // ❌ Per-day limit exceeded
        if ($dayCount >= $perDay) {
            $result['allowed']    = false;
            $result['retryAfter'] = 3600; // Retry after 1 hour (approximate)
            return $result;
        }
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('warning', 'Rate limit day check failed', $e->getMessage());
        }
        return $result;
    }

    return $result;
}

// ==============================================================================
// 📝 recordAPIRequest() - Record an API request in the rate log
// ==============================================================================

/**
 * 📝 Record an API request in the tblAPIRateLog table
 *
 * Inserts a row into the rate log for tracking purposes. This should be
 * called after successful authentication and rate limit checks, before
 * processing the actual request.
 *
 * Also triggers periodic cleanup of old rate log entries (approximately
 * once every 100 requests, based on random chance).
 *
 * @param int|null $apiKeyId    The tblAPIKeys.apiKeyID (null for anonymous/CSRF)
 * @param string   $ipAddress   The client IP address
 * @param string   $requestPath The API request path (e.g., 'generate', 'types')
 *
 * @return void
 *
 * @see https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php
 * @see https://www.php.net/manual/en/function.random-int.php
 *
 * @example
 *   recordAPIRequest($auth['apiKeyId'], $clientIP, 'generate');
 */
function recordAPIRequest(?int $apiKeyId, string $ipAddress, string $requestPath): void
{
    if (!function_exists('dbQuery')) {
        return;
    }

    try {
        $stmt = dbQuery(
            "INSERT INTO `tblAPIRateLog` ("
            . "`apiKeyID`, `requestIP`, `endpoint`, `requestedAt`"
            . ") VALUES (?, ?, ?, NOW())",
            'iss',
            [
                $apiKeyId,
                mb_substr($ipAddress, 0, 45),
                mb_substr($requestPath, 0, 255),
            ]
        );
        $stmt->close();
    } catch (\Throwable $e) {
        // ⚠️ Non-critical: log but don't fail the API request
        if (function_exists('logError')) {
            logError('warning', 'Failed to record API request in rate log', $e->getMessage());
        }
    }

    // ==================================================================
    // 🧹 Periodic cleanup: ~1% chance per request
    // Rather than running a cron job, we piggyback cleanup on normal
    // API traffic. This keeps the rate log table from growing unbounded.
    // @see https://www.php.net/manual/en/function.random-int.php
    // ==================================================================
    try {
        if (random_int(1, 100) === 1) {
            cleanupRateLog();
        }
    } catch (\Throwable $e) {
        // ⚠️ Cleanup failure is non-critical
    }
}

// ==============================================================================
// 🧹 cleanupRateLog() - Delete old rate log entries
// ==============================================================================

/**
 * 🧹 Delete rate log entries older than 24 hours
 *
 * Removes expired entries from tblAPIRateLog to prevent the table from
 * growing unbounded. Since rate limits only check the last 24 hours,
 * older entries serve no purpose.
 *
 * This function is called periodically (via random chance in
 * recordAPIRequest()) rather than on every request to minimise
 * database overhead.
 *
 * @return void
 *
 * @see https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php
 * @see https://dev.mysql.com/doc/refman/8.0/en/date-and-time-functions.html#function_date-sub
 *
 * @example
 *   cleanupRateLog(); // Deletes all entries older than 24 hours
 */
function cleanupRateLog(): void
{
    if (!function_exists('dbQuery')) {
        return;
    }

    try {
        $stmt = dbQuery(
            "DELETE FROM `tblAPIRateLog` "
            . "WHERE `requestedAt` < DATE_SUB(NOW(), INTERVAL 24 HOUR)",
            '',
            []
        );
        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('warning', 'Rate log cleanup failed', $e->getMessage());
        }
    }
}

// ==============================================================================
// 🌍 getClientIP() - Get the client IP address
// ==============================================================================

/**
 * 🌍 Get the client IP address from the current request
 *
 * Checks multiple sources for the client IP in priority order:
 *   1. X-Forwarded-For header (first IP if comma-separated)
 *   2. X-Real-IP header
 *   3. REMOTE_ADDR server variable
 *
 * The first IP in X-Forwarded-For is used because it represents the
 * original client; subsequent IPs are intermediate proxies.
 *
 * @return string The client IP address, or 'unknown' if not available
 *
 * @see https://www.php.net/manual/en/reserved.variables.server.php
 * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/X-Forwarded-For
 *
 * @example
 *   $clientIP = getClientIP();
 *   // Returns: '192.168.1.1' or '2001:db8::1' or 'unknown'
 */
function getClientIP(): string
{
    // 📌 Get the direct connection IP (cannot be spoofed)
    // @see https://www.php.net/manual/en/reserved.variables.server.php
    if (isset($_SERVER['REMOTE_ADDR'])) {
        $remoteAddr = $_SERVER['REMOTE_ADDR'];
    } else {
        $remoteAddr = 'unknown';
    }

    // 📌 Only trust proxy headers if REMOTE_ADDR is from a configured trusted proxy.
    // Without a trusted proxy list, proxy headers (X-Forwarded-For, X-Real-IP) can be
    // spoofed by any client to bypass rate limiting.
    // @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/X-Forwarded-For
    $trustedProxies = [];
    if (function_exists('getConfig')) {
        $configProxies = getConfig('trusted_proxy_ips', '');
        if ($configProxies !== '' && $configProxies !== null) {
            $trustedProxies = array_map('trim', explode(',', $configProxies));
        }
    }

    if (in_array($remoteAddr, $trustedProxies, true)) {
        // 📌 Request is from a trusted proxy - safe to use forwarded headers
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (isset($_SERVER['HTTP_X_REAL_IP'])) {
            $ip = $_SERVER['HTTP_X_REAL_IP'];
        } else {
            $ip = $remoteAddr;
        }

        // 📌 X-Forwarded-For may contain multiple IPs: "client, proxy1, proxy2"
        // Take the first (original client) IP.
        if (strpos($ip, ',') !== false) {
            $ip = trim(explode(',', $ip)[0]);
        }

        // 📌 Validate the IP format before using
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $ip = $remoteAddr;
        }

        return $ip;
    }

    // 📌 Not from a trusted proxy - use REMOTE_ADDR directly
    return $remoteAddr;
}
