<?php
/**
 * ==============================================================================
 * 🔑 CueRCode - Self-Service API Key Management (Epic E7 stage 1, #54)
 * ==============================================================================
 *
 * Path: _functions/fn_api_keys.php
 *
 * Lets an authenticated user manage their OWN `tblAPIKeys` rows: create a new
 * key (with a caller-chosen name and an allowlisted set of scopes), list their
 * own keys (metadata only - never the secret or its hash), rotate a key they
 * own (issuing a new secret, invalidating the old one), and revoke (soft-
 * delete) a key they own. This is the FIRST code in this codebase that can
 * actually CREATE a `tblAPIKeys` row - previously only `fn_api_auth.php`'s
 * `validateAPIKey()` (read-only lookup) existed, and no key could be minted
 * without a direct database INSERT.
 *
 * Endpoints handled (router: public_html/api/v1/index.php):
 *   - POST   /api/v1/keys              -> handleApiKeysCollection() -> handleCreateApiKey()
 *   - GET    /api/v1/keys              -> handleApiKeysCollection() -> handleListApiKeys()
 *   - POST   /api/v1/keys/{id}/rotate  -> handleRotateApiKey()
 *   - DELETE /api/v1/keys/{id}         -> handleDeleteApiKey()
 *
 * Split, like fn_auth.php / fn_api_account.php, into:
 *   1. "Engine" functions (createApiKeyForUser(), rotateApiKeyForUser(),
 *      revokeApiKeyForUser(), listApiKeysForUser()) - framework-agnostic, no
 *      jsonResponse()/errorResponse() calls, plain scalar/array args, plain
 *      result arrays. Reused by BOTH the JSON API handlers below AND
 *      public_html/account/index.php's own no-JS <form> POST fallback, so the
 *      two intake paths share one code path per concern rather than diverging
 *      (exactly the fn_api_account.php convention).
 *   2. HTTP handler functions (handleCreateApiKey(), etc.) - read the router's
 *      parsed JSON body / route params, validate shape, call the engine
 *      functions, and emit errorResponse()/successResponse().
 *
 * SECURITY POSTURE (see SECURITY.md for the audit recorded for this stage):
 *   - Own-scoped everywhere: every function resolves the caller's userID from
 *     the SERVER-SIDE session/API-key identity ONLY (resolveUserIdFromAuth(),
 *     fn_features.php) - there is no "user_id"/"owner"/"target" field anywhere
 *     in these request bodies that has any effect.
 *   - IDOR posture (mirrors fn_api_qrcodes.php exactly): every single-key route
 *     (rotate/revoke) re-fetches the row via fetchApiKeyRowById() and checks
 *     ownership via apiKeyIsOwnedBy() BEFORE any mutation - there is no code
 *     path that trusts a prior check. A key that is NOT owned by the caller is
 *     reported as 404 NOT_FOUND, never 403 (a 403 would confirm the id exists
 *     and belongs to someone else - the same non-disclosure convention
 *     fn_api_qrcodes.php/fn_api_account.php established).
 *   - The full raw key is returned ONLY in the create/rotate HTTP response
 *     body, exactly once, and is NEVER stored anywhere (only its HMAC-SHA256
 *     hash, hashAPIKey(), is persisted - fn_encryption.php, unchanged) and
 *     NEVER logged (logActivity() calls below only ever pass the key's NAME/id,
 *     never the raw key or its hash).
 *   - **Owner/design decision - API-key credentials cannot manage API keys**:
 *     every route in this file requires the caller to be authenticated via
 *     CSRF/session (the web account dashboard), NOT via X-API-Key
 *     (apiKeyManagementAllowedFor()). Rationale: if a key-authenticated caller
 *     could mint a NEW key, a compromised low-scope key (e.g. only
 *     "generate") could self-escalate by creating a brand-new key with EVERY
 *     scope - the allowlist validation has no way to cap a newly-requested
 *     key's scopes to the calling key's own scopes, so the safest default is
 *     to require an interactive session for all key-management actions
 *     (create/list/rotate/revoke) including read-only listing (which would
 *     otherwise hand a compromised key reconnaissance over the account's
 *     other keys). This is a deliberate, reversible launch-time choice - see
 *     DEV_NOTES.md.
 *   - Per-plan cap: `limitFor('api_keys', userID)` (already seeded per tier in
 *     seed_data.sql - anonymous=0, free=1, pro=5, business=20) is enforced on
 *     CREATE only (rotate/revoke never create a new row, so they never need
 *     the cap check). A simple COUNT-then-INSERT is used (NOT a MySQL
 *     advisory lock like fn_api_qrcodes.php's F-023 fix) - see DEV_NOTES.md
 *     for the accepted-risk rationale (a human clicking "create key" does not
 *     realistically fire concurrent requests, and the worst case is a low-
 *     severity, same-user, off-by-a-few cap overshoot, not a cross-user
 *     boundary violation).
 *
 * @package     CueRCode
 * @subpackage  API
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 2 (Epic E7 stage 1, #54)
 * @see         fn_api_auth.php      - validateAPIKey() (the read-only key lookup this
 *                                     stage's rows feed), apiKeyRequestHasScope() (scope enforcement)
 * @see         fn_encryption.php    - generateAPIKey()/hashAPIKey()/getAPIKeyPrefix()
 * @see         fn_features.php      - resolveUserIdFromAuth(), limitFor('api_keys', ...)
 * @see         fn_api_qrcodes.php   - the ownership/IDOR pattern this file replicates
 * @see         SECURITY.md          - the IDOR + scope-enforcement audit recorded for this stage
 * @see         https://owasp.org/www-project-top-ten/2017/A5_2017-Broken_Access_Control OWASP IDOR guidance
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
// 🔧 Constants
// ==============================================================================

/**
 * @var array The COMPLETE allowlist of scope strings a self-service key may be
 * granted. This is the single source of truth - both request-time validation
 * (validateApiKeyScopesInput()) and the account-dashboard checkbox UI
 * (apiKeyScopeLabel() below) iterate this exact list. Adding a new scope is a
 * one-line change here (plus wiring the corresponding route's
 * 'requiredScope' in public_html/api/v1/index.php).
 */
if (!defined('CUERCODE_API_KEY_SCOPE_ALLOWLIST')) {
    define('CUERCODE_API_KEY_SCOPE_ALLOWLIST', [
        'generate',
        'preview',
        'download',
        'bulk',
        'qrcodes:read',
        'qrcodes:write',
        'save',
    ]);
}

/** @var int Maximum length of a caller-chosen key name (matches tblAPIKeys.apiKeyName VARCHAR(100)). */
if (!defined('CUERCODE_API_KEY_NAME_MAX_LEN')) {
    define('CUERCODE_API_KEY_NAME_MAX_LEN', 100);
}

/** @var string Route-parameter shape for a tblAPIKeys.apiKeyID (a positive integer, no leading zero). */
if (!defined('CUERCODE_API_KEY_ID_REGEX')) {
    define('CUERCODE_API_KEY_ID_REGEX', '/^[1-9][0-9]*$/');
}

// ==============================================================================
// 🧮 Pure helpers (no DB, no I/O) - unit-testable in isolation
// ==============================================================================

// ------------------------------------------------------------------------
// 🎫 apiKeyManagementAllowedFor() - CSRF-only guard (owner/design decision)
// ------------------------------------------------------------------------

/**
 * 🎫 Is this authentication context allowed to manage API keys?
 *
 * Per this file's header docblock ("Owner/design decision"), ONLY a
 * CSRF/session-authenticated caller (the web account dashboard) may
 * create/list/rotate/revoke API keys - an X-API-Key-authenticated caller is
 * refused (403 API_KEY_MANAGEMENT_FORBIDDEN) regardless of what scopes that
 * key holds, closing the self-escalation path where a leaked low-scope key
 * could mint itself a brand-new, broader-scoped key.
 *
 * @param array $auth The router's authentication context (fn_api_auth.php).
 *
 * @return bool True only when $auth['method'] === 'csrf'.
 */
function apiKeyManagementAllowedFor(array $auth): bool
{
    return isset($auth['method']) && $auth['method'] === 'csrf';
}

// ------------------------------------------------------------------------
// 🆔 isValidApiKeyId() - route-parameter shape check
// ------------------------------------------------------------------------

/**
 * 🆔 Validate that a route parameter looks like a genuine tblAPIKeys.apiKeyID.
 *
 * @param string $id The candidate route-parameter string.
 *
 * @return bool True if it is a positive integer with no leading zero.
 */
function isValidApiKeyId(string $id): bool
{
    return preg_match(CUERCODE_API_KEY_ID_REGEX, $id) === 1;
}

// ------------------------------------------------------------------------
// 🔐 apiKeyIsOwnedBy() - the single source of truth for key ownership
// ------------------------------------------------------------------------

/**
 * 🔐 Is a tblAPIKeys row owned by the given caller? Mirrors
 * fn_api_qrcodes.php's qrCodeIsWritableBy() signature/contract exactly.
 *
 * @param int|null $ownerUserId  The row's userID (null = not user-owned, e.g. a
 *                                future org-owned key - tblAPIKeys.orgID is not
 *                                populated by anything in this codebase yet).
 * @param int|null $callerUserId The resolved caller identity (null = could not
 *                                be resolved - treated as "nobody").
 *
 * @return bool True if owned by the caller.
 */
function apiKeyIsOwnedBy(?int $ownerUserId, ?int $callerUserId): bool
{
    return $ownerUserId !== null && $callerUserId !== null && $ownerUserId === $callerUserId;
}

// ------------------------------------------------------------------------
// 🏷️ validateApiKeyNameInput() - "name" field validation
// ------------------------------------------------------------------------

/**
 * 🏷️ Validate a caller-supplied key name (required - unlike the saved-codes
 * "label" field, a key MUST be named so the owner can tell their keys apart
 * in the dashboard list).
 *
 * @param mixed $rawName The raw value from the request body / form field.
 *
 * @return array{valid: bool, value: string|null, error: string|null}
 */
function validateApiKeyNameInput(mixed $rawName): array
{
    if (!is_string($rawName)) {
        return ['valid' => false, 'value' => null, 'error' => 'Field "name" is required and must be a string.'];
    }

    $trimmed = trim($rawName);

    if ($trimmed === '') {
        return ['valid' => false, 'value' => null, 'error' => 'Field "name" is required and cannot be empty.'];
    }

    if (mb_strlen($trimmed) > CUERCODE_API_KEY_NAME_MAX_LEN) {
        return [
            'valid' => false,
            'value' => null,
            'error' => 'Field "name" is too long. Maximum ' . CUERCODE_API_KEY_NAME_MAX_LEN . ' characters.',
        ];
    }

    return ['valid' => true, 'value' => $trimmed, 'error' => null];
}

// ------------------------------------------------------------------------
// 🔒 validateApiKeyScopesInput() - "scopes" field validation (fail-closed)
// ------------------------------------------------------------------------

/**
 * 🔒 Validate a caller-supplied "scopes" array against the allowlist.
 *
 * An absent/null "scopes" field is valid and resolves to an EMPTY array - a
 * key with no scopes can do nothing scoped (fail-closed by omission, per this
 * stage's security posture). Every requested scope must appear in
 * CUERCODE_API_KEY_SCOPE_ALLOWLIST or the WHOLE request is rejected (never
 * silently drop an unrecognised scope - the caller must know it was refused).
 *
 * @param mixed $rawScopes The raw "scopes" value from the request body / form
 *                          field (expected: array of strings, or a single
 *                          string/array of strings from a checkbox POST).
 *
 * @return array{valid: bool, value: array, error: string|null} `value` is the
 *         de-duplicated, normalised (lower-cased, trimmed) scope list on success.
 */
function validateApiKeyScopesInput(mixed $rawScopes): array
{
    if ($rawScopes === null) {
        return ['valid' => true, 'value' => [], 'error' => null];
    }

    if (!is_array($rawScopes)) {
        return ['valid' => false, 'value' => [], 'error' => 'Field "scopes" must be an array of scope strings.'];
    }

    $normalized = [];
    $unknown    = [];

    foreach ($rawScopes as $rawScope) {
        if (!is_string($rawScope)) {
            return ['valid' => false, 'value' => [], 'error' => 'Field "scopes" must contain only strings.'];
        }

        $scope = strtolower(trim($rawScope));

        if ($scope === '') {
            // 📌 A blank entry (e.g. an empty checkbox value) is silently
            // skipped - it is not a caller error, just nothing to grant.
            continue;
        }

        if (!in_array($scope, CUERCODE_API_KEY_SCOPE_ALLOWLIST, true)) {
            $unknown[] = $scope;
            continue;
        }

        if (!in_array($scope, $normalized, true)) {
            $normalized[] = $scope;
        }
    }

    if (!empty($unknown)) {
        return [
            'valid' => false,
            'value' => [],
            'error' => 'Unknown scope(s): ' . implode(', ', $unknown) . '. Allowed scopes: '
                . implode(', ', CUERCODE_API_KEY_SCOPE_ALLOWLIST) . '.',
        ];
    }

    return ['valid' => true, 'value' => $normalized, 'error' => null];
}

// ------------------------------------------------------------------------
// 🏷️ apiKeyScopeLabel() - human-readable label for one scope (UI helper)
// ------------------------------------------------------------------------

/**
 * 🏷️ Resolve a short, human-readable label for a scope string, for the
 * account-dashboard checkbox list and existing-key scope badges.
 *
 * @param string $scope A scope string (ideally one from CUERCODE_API_KEY_SCOPE_ALLOWLIST).
 *
 * @return string The label, or the raw scope string itself if unrecognised
 *                (fail-safe - a future scope added to the allowlist but not
 *                yet given a label here still renders something sensible).
 */
function apiKeyScopeLabel(string $scope): string
{
    $labels = [
        'generate'      => 'Generate QR codes',
        'preview'       => 'Preview QR codes (no save)',
        'download'      => 'Download previously generated QR codes',
        'bulk'          => 'Bulk-generate QR codes',
        'qrcodes:read'  => 'View your saved QR code library',
        'qrcodes:write' => 'Edit or delete your saved QR codes',
        'save'          => 'Save a generated QR code to your library',
    ];

    if (isset($labels[$scope])) {
        return $labels[$scope];
    }

    return $scope;
}

// ------------------------------------------------------------------------
// ⏱️ resolveClampedApiKeyRateLimits() - plan-derived rate limits for a NEW key
// ------------------------------------------------------------------------

/**
 * ⏱️ Resolve the per-minute/per-day rate limits a brand-new key should be
 * created with, derived from the owning user's CURRENT plan.
 *
 * Mirrors fn_features.php's syncApiKeyLimitsForUser() clamp EXACTLY:
 * tblAPIKeys.rateLimitPerMin/PerDay are unsigned INT columns with no "-1 =
 * unlimited" sentinel (unlike limitsJSON's own convention), so an unlimited
 * plan's -1 is clamped to the SAME large-but-finite ceiling used there - a
 * key created today and a key re-synced tomorrow (e.g. after a plan change)
 * land on numerically identical values.
 *
 * @param int $userId The owning tblUsers.userID.
 *
 * @return array{0: int, 1: int} [perMin, perDay].
 *
 * @see fn_features.php - syncApiKeyLimitsForUser() (the mirrored clamp)
 */
function resolveClampedApiKeyRateLimits(int $userId): array
{
    if (function_exists('limitFor')) {
        $perMin = limitFor('api_rate_limit_per_min', $userId);
        $perDay = limitFor('api_rate_limit_per_day', $userId);
    } else {
        // 📌 Defensive fallback only - fn_features.php is always loaded
        // alongside this file by the router in normal operation.
        $perMin = 10;
        $perDay = 100;
    }

    if ($perMin < 0) {
        $perMin = 1000000;
    }
    if ($perDay < 0) {
        $perDay = 100000000;
    }

    return [$perMin, $perDay];
}

// ==============================================================================
// 🗄️ DB-touching helpers
// ==============================================================================

// ------------------------------------------------------------------------
// 🔎 fetchApiKeyRowById() - The ONE lookup every single-key route uses
// ------------------------------------------------------------------------

/**
 * 🔎 Fetch a tblAPIKeys row by its apiKeyID. Deliberately never SELECTs
 * `apiKeyHash` - nothing in this file ever needs to READ the hash (only
 * overwrite it, on rotate), so it is never pulled into a PHP array where it
 * could be accidentally logged/serialised/returned.
 *
 * @param int $apiKeyId The tblAPIKeys.apiKeyID to look up.
 *
 * @return array|null The row (apiKeyID, apiKeyPrefix, apiKeyName, userID,
 *                     permissions, rateLimitPerMin, rateLimitPerDay, isActive,
 *                     lastUsedAt, createdAt, expiresAt), or null if not found /
 *                     DB unavailable.
 */
function fetchApiKeyRowById(int $apiKeyId): ?array
{
    if (!function_exists('dbQuery')) {
        return null;
    }

    try {
        $stmt = dbQuery(
            'SELECT `apiKeyID`, `apiKeyPrefix`, `apiKeyName`, `userID`, `permissions`, '
            . '`rateLimitPerMin`, `rateLimitPerDay`, `isActive`, `lastUsedAt`, `createdAt`, `expiresAt` '
            . 'FROM `tblAPIKeys` WHERE `apiKeyID` = ? LIMIT 1',
            'i',
            [$apiKeyId]
        );
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row;
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'API key lookup by id failed', $e->getMessage());
        }
        return null;
    }
}

// ------------------------------------------------------------------------
// 🔢 countActiveApiKeysForUser() - live count backing the api_keys plan limit
// ------------------------------------------------------------------------

/**
 * 🔢 Count a user's currently-active (isActive = 1) API keys.
 *
 * @param int $userId The tblUsers.userID to count for.
 *
 * @return int The live count (0 if the DB is unavailable or the query fails -
 *             fail-open on the COUNT itself; the actual create is still gated
 *             by limitFor() immediately afterwards).
 */
function countActiveApiKeysForUser(int $userId): int
{
    if (!function_exists('dbQuery')) {
        return 0;
    }

    try {
        $stmt = dbQuery(
            'SELECT COUNT(*) AS `activeCount` FROM `tblAPIKeys` WHERE `userID` = ? AND `isActive` = 1',
            'i',
            [$userId]
        );
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row !== null && isset($row['activeCount'])) {
            return (int) $row['activeCount'];
        }

        return 0;
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'Active API key count failed', 'userID: ' . $userId . ' | ' . $e->getMessage());
        }
        return 0;
    }
}

// ==============================================================================
// 🧩 Engine functions - framework-agnostic, DB-touching, no HTTP shaping
// ==============================================================================

// ------------------------------------------------------------------------
// 🆕 createApiKeyForUser() - the ONE code path that mints a new key
// ------------------------------------------------------------------------

/**
 * 🆕 Create a new self-service API key for a user.
 *
 * Validates the name/scopes, enforces the caller's plan `api_keys` cap
 * (`limitFor('api_keys', $userId)`, -1 = unlimited), generates the key
 * (generateAPIKey()), stores ONLY its HMAC-SHA256 hash + prefix
 * (fn_encryption.php - unchanged), and returns the FULL raw key in the
 * result's `data.api_key` - the ONLY time it is ever available anywhere.
 *
 * @param int    $userId    The caller's own tblUsers.userID (already resolved
 *                          server-side - never a client-supplied value).
 * @param mixed  $rawName   The raw "name" field (validated internally).
 * @param mixed  $rawScopes The raw "scopes" field (validated internally).
 *
 * @return array{success: bool, httpCode: int, errorCode: string|null, message: string, data: array}
 *
 * @see fn_encryption.php - generateAPIKey()/hashAPIKey()/getAPIKeyPrefix()
 * @see fn_features.php   - limitFor('api_keys', ...), resolveUpgradeUrl()
 */
function createApiKeyForUser(int $userId, mixed $rawName, mixed $rawScopes): array
{
    $nameValidation = validateApiKeyNameInput($rawName);

    if (!$nameValidation['valid']) {
        return [
            'success'   => false,
            'httpCode'  => 400,
            'errorCode' => 'INVALID_REQUEST',
            'message'   => (string) $nameValidation['error'],
            'data'      => [],
        ];
    }

    $scopesValidation = validateApiKeyScopesInput($rawScopes);

    if (!$scopesValidation['valid']) {
        return [
            'success'   => false,
            'httpCode'  => 400,
            'errorCode' => 'INVALID_SCOPE',
            'message'   => (string) $scopesValidation['error'],
            'data'      => [],
        ];
    }

    if (!function_exists('dbQuery')) {
        return [
            'success'   => false,
            'httpCode'  => 503,
            'errorCode' => 'SERVICE_UNAVAILABLE',
            'message'   => 'The API key service is temporarily unavailable. Please try again later.',
            'data'      => [],
        ];
    }

    // ------------------------------------------------------------------
    // 💳 Per-plan api_keys cap (Epic E4/#53 gating engine - seeded per tier:
    // anonymous=0, free=1, pro=5, business=20 - seed_data.sql).
    // ------------------------------------------------------------------
    $limit       = function_exists('limitFor') ? limitFor('api_keys', $userId) : 0;
    $activeCount = countActiveApiKeysForUser($userId);

    if ($limit !== -1 && $activeCount >= $limit) {
        $upgradeUrl = function_exists('resolveUpgradeUrl') ? resolveUpgradeUrl() : '/pricing';

        return [
            'success'   => false,
            'httpCode'  => 402,
            'errorCode' => 'API_KEY_LIMIT_REACHED',
            'message'   => 'You have reached your plan\'s API key limit (' . $limit . '). '
                . 'Upgrade your plan to create more keys.',
            'data'      => ['plan_limit' => $limit, 'upgrade_url' => $upgradeUrl],
        ];
    }

    // ------------------------------------------------------------------
    // 🎲 Generate + hash the new key. The raw key is NEVER persisted -
    // only hashAPIKey()'s HMAC-SHA256 digest is stored.
    // ------------------------------------------------------------------
    $rawKey = generateAPIKey();
    $hash   = hashAPIKey($rawKey);
    $prefix = getAPIKeyPrefix($rawKey);

    [$perMin, $perDay] = resolveClampedApiKeyRateLimits($userId);

    $permissionsJson = json_encode(
        $scopesValidation['value'],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    try {
        $insertStmt = dbQuery(
            'INSERT INTO `tblAPIKeys` '
            . '(`apiKeyHash`, `apiKeyPrefix`, `apiKeyName`, `userID`, `permissions`, `rateLimitPerMin`, `rateLimitPerDay`, `isActive`) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, 1)',
            'sssisii',
            [$hash, $prefix, $nameValidation['value'], $userId, $permissionsJson, $perMin, $perDay]
        );
        $newKeyId = (int) $insertStmt->insert_id;
        $insertStmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'API key creation failed', 'userID: ' . $userId . ' | ' . $e->getMessage());
        }
        return [
            'success'   => false,
            'httpCode'  => 500,
            'errorCode' => 'CREATE_FAILED',
            'message'   => 'Your API key could not be created. Please try again.',
            'data'      => [],
        ];
    }

    // 📝 Activity log: NEVER the raw key or its hash - only the id/name.
    if (function_exists('logActivity')) {
        logActivity(
            'api_key_created',
            'Created API key: ' . $nameValidation['value'],
            'api_key',
            $newKeyId,
            ['scopes' => $scopesValidation['value']]
        );
    }

    return [
        'success'   => true,
        'httpCode'  => 201,
        'errorCode' => null,
        'message'   => 'API key created. Copy it now - it will not be shown again.',
        'data'      => [
            'id'           => $newKeyId,
            'api_key'      => $rawKey,
            'prefix'       => $prefix,
            'name'         => $nameValidation['value'],
            'scopes'       => $scopesValidation['value'],
            'rate_limits'  => ['per_min' => $perMin, 'per_day' => $perDay],
            'is_active'    => true,
            'last_used_at' => null,
            'created_at'   => gmdate('Y-m-d H:i:s'),
            'expires_at'   => null,
        ],
    ];
}

// ------------------------------------------------------------------------
// 📋 listApiKeysForUser() - own-scoped list (metadata only, never the secret)
// ------------------------------------------------------------------------

/**
 * 📋 List a user's own API keys (active AND revoked - `is_active` tells them
 * apart in the UI). Unconditionally scoped to `WHERE userID = ?` - there is
 * no parameter that widens this to another user's keys. NEVER includes the
 * full key or its hash.
 *
 * @param int $userId The caller's own tblUsers.userID.
 *
 * @return array List of key rows: id, prefix, name, scopes, rate_limits,
 *               is_active, last_used_at, created_at, expires_at. Empty array
 *               on DB error (fail-closed - never fabricates a key).
 */
function listApiKeysForUser(int $userId): array
{
    if (!function_exists('dbQuery')) {
        return [];
    }

    try {
        $stmt = dbQuery(
            'SELECT `apiKeyID`, `apiKeyPrefix`, `apiKeyName`, `permissions`, `rateLimitPerMin`, '
            . '`rateLimitPerDay`, `isActive`, `lastUsedAt`, `createdAt`, `expiresAt` '
            . 'FROM `tblAPIKeys` WHERE `userID` = ? ORDER BY `createdAt` DESC',
            'i',
            [$userId]
        );
        $result = $stmt->get_result();
        $rows   = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'List API keys failed', 'userID: ' . $userId . ' | ' . $e->getMessage());
        }
        return [];
    }

    $items = [];

    foreach ($rows as $row) {
        $decodedScopes = json_decode((string) $row['permissions'], true);
        if (!is_array($decodedScopes)) {
            $decodedScopes = [];
        }

        // 🛡️ Defensive re-normalisation: a malformed/legacy permissions value
        // (e.g. a non-string element) can never leak an unexpected type out
        // of this function.
        $normalizedScopes = [];
        foreach ($decodedScopes as $scopeValue) {
            if (is_string($scopeValue)) {
                $normalizedScopes[] = $scopeValue;
            }
        }

        $items[] = [
            'id'           => (int) $row['apiKeyID'],
            'prefix'       => (string) $row['apiKeyPrefix'],
            'name'         => $row['apiKeyName'],
            'scopes'       => $normalizedScopes,
            'rate_limits'  => [
                'per_min' => (int) $row['rateLimitPerMin'],
                'per_day' => (int) $row['rateLimitPerDay'],
            ],
            'is_active'    => ((int) $row['isActive']) === 1,
            'last_used_at' => $row['lastUsedAt'],
            'created_at'   => (string) $row['createdAt'],
            'expires_at'   => $row['expiresAt'],
        ];
    }

    return $items;
}

// ------------------------------------------------------------------------
// 🔄 rotateApiKeyForUser() - own-scoped rotate (new secret, same row)
// ------------------------------------------------------------------------

/**
 * 🔄 Rotate an API key the caller OWNS: issues a new secret (returned once)
 * and invalidates the old one (the hash is overwritten in place - the OLD
 * raw key immediately stops validating via validateAPIKey(), since the hash
 * lookup no longer matches it). The row's identity (apiKeyID, name, scopes,
 * rate limits) is preserved - only the secret changes.
 *
 * @param int $callerUserId The caller's own tblUsers.userID.
 * @param int $apiKeyId     The tblAPIKeys.apiKeyID to rotate.
 *
 * @return array{success: bool, httpCode: int, errorCode: string|null, message: string, data: array}
 *
 * @see apiKeyIsOwnedBy() - the IDOR guard applied before any mutation
 */
function rotateApiKeyForUser(int $callerUserId, int $apiKeyId): array
{
    if (!function_exists('dbQuery')) {
        return [
            'success'   => false,
            'httpCode'  => 503,
            'errorCode' => 'SERVICE_UNAVAILABLE',
            'message'   => 'The API key service is temporarily unavailable. Please try again later.',
            'data'      => [],
        ];
    }

    $row = fetchApiKeyRowById($apiKeyId);

    $ownerId = ($row !== null && $row['userID'] !== null) ? (int) $row['userID'] : null;

    // 🔐 IDOR guard: not owned (or does not exist) -> 404 (never 403 - see
    // this file's header docblock for the non-disclosure rationale).
    if ($row === null || !apiKeyIsOwnedBy($ownerId, $callerUserId)) {
        return [
            'success'   => false,
            'httpCode'  => 404,
            'errorCode' => 'NOT_FOUND',
            'message'   => 'API key not found.',
            'data'      => [],
        ];
    }

    $rawKey = generateAPIKey();
    $hash   = hashAPIKey($rawKey);
    $prefix = getAPIKeyPrefix($rawKey);

    try {
        // 🔒 The UPDATE re-checks ownership AND isActive = 1 in the SAME
        // statement as the mutation - a revoked key cannot be silently
        // "un-revoked" via rotate, and a race against a concurrent revoke
        // cannot rotate a key out from under it.
        $updateStmt = dbQuery(
            'UPDATE `tblAPIKeys` SET `apiKeyHash` = ?, `apiKeyPrefix` = ? '
            . 'WHERE `apiKeyID` = ? AND `userID` = ? AND `isActive` = 1',
            'ssii',
            [$hash, $prefix, $apiKeyId, $callerUserId]
        );
        $rotated = ($updateStmt->affected_rows === 1);
        $updateStmt->close();
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'API key rotation failed', 'apiKeyID: ' . $apiKeyId . ' | ' . $e->getMessage());
        }
        return [
            'success'   => false,
            'httpCode'  => 500,
            'errorCode' => 'ROTATE_FAILED',
            'message'   => 'Your API key could not be rotated. Please try again.',
            'data'      => [],
        ];
    }

    if (!$rotated) {
        // 📌 Owned but already revoked (isActive = 0) - reported identically
        // to "not found", mirroring fn_api_account.php's revokeUserSession()
        // convention (no special-cased message even for the genuine owner).
        return [
            'success'   => false,
            'httpCode'  => 404,
            'errorCode' => 'NOT_FOUND',
            'message'   => 'API key not found.',
            'data'      => [],
        ];
    }

    if (function_exists('logActivity')) {
        logActivity(
            'api_key_rotated',
            'Rotated API key: ' . (string) $row['apiKeyName'],
            'api_key',
            $apiKeyId,
            []
        );
    }

    return [
        'success'   => true,
        'httpCode'  => 200,
        'errorCode' => null,
        'message'   => 'API key rotated. Copy the new key now - it will not be shown again.',
        'data'      => [
            'id'      => $apiKeyId,
            'api_key' => $rawKey,
            'prefix'  => $prefix,
            'name'    => $row['apiKeyName'],
        ],
    ];
}

// ------------------------------------------------------------------------
// 🚫 revokeApiKeyForUser() - own-scoped soft-revoke (isActive = 0)
// ------------------------------------------------------------------------

/**
 * 🚫 Revoke (soft-delete) an API key the caller OWNS. Sets `isActive = 0`
 * rather than deleting the row - matching this codebase's existing
 * reversible-state convention (tblUserSessions.revokedAt,
 * tblQRCodes.deletedAt) so a revoked key's audit trail (createdAt, name,
 * lastUsedAt, requestCount) survives for the account owner and any future
 * admin review. `validateAPIKey()` already refuses `isActive = 0` rows, so a
 * revoked key stops authenticating immediately.
 *
 * @param int $callerUserId The caller's own tblUsers.userID.
 * @param int $apiKeyId     The tblAPIKeys.apiKeyID to revoke.
 *
 * @return bool True if a row was actually revoked, false if not found/not
 *              owned/already revoked, or on a database error.
 *
 * @see apiKeyIsOwnedBy() - the IDOR guard applied before any mutation
 */
function revokeApiKeyForUser(int $callerUserId, int $apiKeyId): bool
{
    if (!function_exists('dbQuery')) {
        return false;
    }

    $row = fetchApiKeyRowById($apiKeyId);

    $ownerId = ($row !== null && $row['userID'] !== null) ? (int) $row['userID'] : null;

    if ($row === null || !apiKeyIsOwnedBy($ownerId, $callerUserId)) {
        return false;
    }

    try {
        $updateStmt = dbQuery(
            'UPDATE `tblAPIKeys` SET `isActive` = 0 WHERE `apiKeyID` = ? AND `userID` = ? AND `isActive` = 1',
            'ii',
            [$apiKeyId, $callerUserId]
        );
        $revoked = ($updateStmt->affected_rows === 1);
        $updateStmt->close();

        return $revoked;
    } catch (\Throwable $e) {
        if (function_exists('logError')) {
            logError('error', 'API key revoke failed', 'apiKeyID: ' . $apiKeyId . ' | ' . $e->getMessage());
        }
        return false;
    }
}

// ==============================================================================
// 🌐 HTTP handler functions - JSON API router-facing
// ==============================================================================

// ------------------------------------------------------------------------
// 🆕 handleCreateApiKey() - POST /api/v1/keys
// ------------------------------------------------------------------------

/**
 * 🆕 Handle POST /api/v1/keys
 *
 * @param array $requestBody The parsed JSON request body ("name", "scopes").
 * @param array $auth        The router's authentication context.
 *
 * @return void (terminates via errorResponse()/successResponse())
 */
function handleCreateApiKey(array $requestBody, array $auth): void
{
    if (!apiKeyManagementAllowedFor($auth)) {
        errorResponse(
            'API keys cannot be used to manage other API keys. Please sign in and use the account dashboard.',
            403,
            'API_KEY_MANAGEMENT_FORBIDDEN'
        );
        return;
    }

    $currentUserId = function_exists('resolveUserIdFromAuth') ? resolveUserIdFromAuth($auth) : null;

    if ($currentUserId === null) {
        errorResponse('Unable to resolve the authenticated account for this request.', 401, 'AUTH_REQUIRED');
        return;
    }

    $requestedName   = $requestBody['name'] ?? null;
    $requestedScopes = $requestBody['scopes'] ?? null;

    $result = createApiKeyForUser($currentUserId, $requestedName, $requestedScopes);

    if (!$result['success']) {
        errorResponse($result['message'], $result['httpCode'], (string) $result['errorCode'], $result['data']);
        return;
    }

    successResponse($result['data'], $result['message'], $result['httpCode']);
}

// ------------------------------------------------------------------------
// 📋 handleListApiKeys() - GET /api/v1/keys
// ------------------------------------------------------------------------

/**
 * 📋 Handle GET /api/v1/keys
 *
 * @param array $auth The router's authentication context.
 *
 * @return void (terminates via errorResponse()/successResponse())
 */
function handleListApiKeys(array $auth): void
{
    if (!apiKeyManagementAllowedFor($auth)) {
        errorResponse(
            'API keys cannot be used to manage other API keys. Please sign in and use the account dashboard.',
            403,
            'API_KEY_MANAGEMENT_FORBIDDEN'
        );
        return;
    }

    $currentUserId = function_exists('resolveUserIdFromAuth') ? resolveUserIdFromAuth($auth) : null;

    if ($currentUserId === null) {
        errorResponse('Unable to resolve the authenticated account for this request.', 401, 'AUTH_REQUIRED');
        return;
    }

    if (!function_exists('dbQuery')) {
        errorResponse('The API key service is temporarily unavailable.', 503, 'SERVICE_UNAVAILABLE');
        return;
    }

    $keys = listApiKeysForUser($currentUserId);

    successResponse(['keys' => $keys], 'API keys retrieved successfully.');
}

// ------------------------------------------------------------------------
// 🔀 handleApiKeysCollection() - router-facing dispatcher for POST/GET /keys
// ------------------------------------------------------------------------

/**
 * 🔀 Dispatch a "/keys" collection request to the method-specific handler.
 *
 * Mirrors fn_api_qrcodes.php's handleQrCodeItem() convention exactly: the
 * router matches by PATTERN, not by pattern+method, so POST (create) and GET
 * (list) on "keys" share ONE route table entry and ONE handler.
 *
 * @param array $requestBody The parsed JSON request body (only meaningful for POST).
 * @param array $auth        The router's authentication context.
 *
 * @return void (delegates to a handler that sends the response and exits)
 */
function handleApiKeysCollection(array $requestBody, array $auth): void
{
    if (isset($_SERVER['REQUEST_METHOD'])) {
        $method = strtoupper($_SERVER['REQUEST_METHOD']);
    } else {
        $method = 'GET';
    }

    switch ($method) {
        case 'POST':
            handleCreateApiKey($requestBody, $auth);
            return;

        case 'GET':
        default:
            handleListApiKeys($auth);
            return;
    }
}

// ------------------------------------------------------------------------
// 🔄 handleRotateApiKey() - POST /api/v1/keys/{id}/rotate
// ------------------------------------------------------------------------

/**
 * 🔄 Handle POST /api/v1/keys/{id}/rotate
 *
 * @param string $id   The tblAPIKeys.apiKeyID route parameter (raw string).
 * @param array  $auth The router's authentication context.
 *
 * @return void (terminates via errorResponse()/successResponse())
 */
function handleRotateApiKey(string $id, array $auth): void
{
    if (!apiKeyManagementAllowedFor($auth)) {
        errorResponse(
            'API keys cannot be used to manage other API keys. Please sign in and use the account dashboard.',
            403,
            'API_KEY_MANAGEMENT_FORBIDDEN'
        );
        return;
    }

    if (!isValidApiKeyId($id)) {
        errorResponse('Invalid API key identifier.', 400, 'INVALID_REQUEST');
        return;
    }

    $currentUserId = function_exists('resolveUserIdFromAuth') ? resolveUserIdFromAuth($auth) : null;

    if ($currentUserId === null) {
        errorResponse('Unable to resolve the authenticated account for this request.', 401, 'AUTH_REQUIRED');
        return;
    }

    if (!function_exists('dbQuery')) {
        errorResponse('The API key service is temporarily unavailable.', 503, 'SERVICE_UNAVAILABLE');
        return;
    }

    $result = rotateApiKeyForUser($currentUserId, (int) $id);

    if (!$result['success']) {
        errorResponse($result['message'], $result['httpCode'], (string) $result['errorCode']);
        return;
    }

    successResponse($result['data'], $result['message'], $result['httpCode']);
}

// ------------------------------------------------------------------------
// 🚫 handleDeleteApiKey() - DELETE /api/v1/keys/{id}
// ------------------------------------------------------------------------

/**
 * 🚫 Handle DELETE /api/v1/keys/{id}
 *
 * @param string $id   The tblAPIKeys.apiKeyID route parameter (raw string).
 * @param array  $auth The router's authentication context.
 *
 * @return void (terminates via errorResponse()/successResponse())
 */
function handleDeleteApiKey(string $id, array $auth): void
{
    if (!apiKeyManagementAllowedFor($auth)) {
        errorResponse(
            'API keys cannot be used to manage other API keys. Please sign in and use the account dashboard.',
            403,
            'API_KEY_MANAGEMENT_FORBIDDEN'
        );
        return;
    }

    if (!isValidApiKeyId($id)) {
        errorResponse('Invalid API key identifier.', 400, 'INVALID_REQUEST');
        return;
    }

    $currentUserId = function_exists('resolveUserIdFromAuth') ? resolveUserIdFromAuth($auth) : null;

    if ($currentUserId === null) {
        errorResponse('Unable to resolve the authenticated account for this request.', 401, 'AUTH_REQUIRED');
        return;
    }

    if (!function_exists('dbQuery')) {
        errorResponse('The API key service is temporarily unavailable.', 503, 'SERVICE_UNAVAILABLE');
        return;
    }

    $apiKeyId = (int) $id;

    if (!revokeApiKeyForUser($currentUserId, $apiKeyId)) {
        // 🔐 Not found, not owned, or already revoked - all reported
        // identically as 404 (never 403 - see this file's header docblock).
        errorResponse('API key not found.', 404, 'NOT_FOUND');
        return;
    }

    if (function_exists('logActivity')) {
        logActivity('api_key_revoked', 'Revoked API key ID: ' . $apiKeyId, 'api_key', $apiKeyId, []);
    }

    successResponse([], 'API key revoked successfully.');
}
