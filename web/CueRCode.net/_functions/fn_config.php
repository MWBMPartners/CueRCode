<?php
/**
 * ==============================================================================
 * ⚙️ CueRCode - Configuration Manager
 * ==============================================================================
 *
 * Path: _functions/fn_config.php
 *
 * Provides functions for reading and writing application settings stored in
 * the tblSettings database table. Settings marked as isSensitive are
 * automatically encrypted/decrypted using fn_encryption.php.
 *
 * All fetched settings are cached in a static array for the duration of the
 * request, eliminating redundant database queries.
 *
 * Values are type-cast according to the settingType column (string, integer,
 * float, boolean, json, color, url, path, email).
 *
 * @package     CueRCode
 * @subpackage  Configuration
 * @copyright   2025–2026 MWBM Partners Ltd
 * @version     1.0.0
 * @since       Phase 1
 * @see         https://www.php.net/manual/en/language.types.type-juggling.php
 * @see         https://www.php.net/manual/en/function.settype.php
 * @see         https://dev.mysql.com/doc/refman/8.0/en/enum.html
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
// 📦 Dependency: fn_encryption.php must be loaded for sensitive value handling
// The encryptValue() and decryptValue() functions are used when isSensitive = 1.
// @see fn_encryption.php
// ==============================================================================

// ==============================================================================
// ⚙️ getConfig() - Fetch a single configuration setting
// ==============================================================================

/**
 * ⚙️ Fetch a single configuration setting by its key
 *
 * Retrieves a setting from tblSettings by settingKey. The value is cached
 * in a static array so subsequent calls for the same key within the same
 * request do not hit the database again.
 *
 * If the setting is marked as isSensitive, the stored value is decrypted
 * using decryptValue() from fn_encryption.php before being returned.
 *
 * The returned value is type-cast according to the settingType column:
 *   - string:  returned as-is (string)
 *   - integer: cast to int
 *   - float:   cast to float
 *   - boolean: cast to bool (0/false/'0' => false, else true)
 *   - json:    decoded to array/object via json_decode()
 *   - color:   returned as string (e.g., '#ff0000')
 *   - url:     returned as string
 *   - path:    returned as string
 *   - email:   returned as string
 *
 * @param string $key     The settingKey to look up (e.g., 'site_name', 'debug_mode')
 * @param mixed  $default Default value to return if the key does not exist
 *
 * @return mixed The setting value (type-cast), or $default if not found
 *
 * @see https://www.php.net/manual/en/language.variables.scope.php#language.variables.scope.static
 * @see https://www.php.net/manual/en/function.json-decode.php
 *
 * @example
 *   $siteName  = getConfig('site_name', 'CueRCode');
 *   $debugMode = getConfig('debug_mode', false);  // Returns bool
 *   $maxSize   = getConfig('max_qr_size', 4000);  // Returns int
 */
function getConfig(string $key, mixed $default = null): mixed
{
    // 📌 Per-request cache lives in the SHARED config cache store (not a local
    //    static) so that setConfig()/clearConfigCache()/populateConfigCache() can
    //    evict and warm entries that getConfig() then honours.
    // 🐛 B-004 FIX: previously getConfig() kept its OWN static $cache that no
    //    other function could touch, so clearConfigCache() (which wrote to an
    //    unrelated static) and populateConfigCache() (a no-op) had ZERO effect —
    //    a stale value could be served after a setConfig() within the same
    //    request. The cache now lives in configCacheStore() and all four
    //    functions operate on it.
    // @see configCacheStore() - the single shared static store
    // 🔍 Return from cache if already fetched/warmed during this request.
    if (configCacheStore('has', $key)) {
        return configCacheStore('get', $key);
    }

    // 🗄️ Query the database for the setting
    // Uses dbQuery() prepared statement from db_connect.php for SQL injection prevention.
    // @see https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php
    try {
        $stmt = dbQuery(
            "SELECT `settingValue`, `settingType`, `isSensitive` "
            . "FROM `tblSettings` "
            . "WHERE `settingKey` = ? "
            . "LIMIT 1",
            's',
            [$key]
        );

        $result = $stmt->get_result();
        $row    = $result->fetch_assoc();
        $stmt->close();
    } catch (\RuntimeException $e) {
        // ❌ Database query failed - log if possible and return default
        // The logError() function may not be available yet (circular dependency guard).
        if (function_exists('logError')) {
            logError('error', 'Config fetch failed', 'Key: ' . $key . ' | ' . $e->getMessage());
        }
        return $default;
    }

    // ❌ Setting not found in database - return the provided default
    if ($row === null) {
        return $default;
    }

    // 📋 Extract row data
    $rawValue    = $row['settingValue'];
    $settingType = $row['settingType'];
    $isSensitive = (int) $row['isSensitive'];

    // 🔓 Decrypt sensitive values before processing
    // Settings with isSensitive = 1 have their settingValue encrypted with
    // AES-256-GCM via encryptValue(). We must decrypt before type-casting.
    // @see fn_encryption.php - decryptValue()
    if ($isSensitive === 1 && $rawValue !== null && $rawValue !== '') {
        if (function_exists('decryptValue')) {
            try {
                $rawValue = decryptValue($rawValue);
            } catch (\RuntimeException $e) {
                // ❌ Decryption failed - log error and return default
                // This could happen if encryption keys have changed.
                if (function_exists('logError')) {
                    logError(
                        'error',
                        'Config decryption failed',
                        'Key: ' . $key . ' | ' . $e->getMessage()
                    );
                }
                return $default;
            }
        } else {
            // ⚠️ fn_encryption.php not loaded - cannot decrypt
            if (function_exists('logError')) {
                logError(
                    'warning',
                    'Cannot decrypt config value',
                    'Key: ' . $key . ' - fn_encryption.php not loaded'
                );
            }
            return $default;
        }
    }

    // 🔄 Type-cast the value based on settingType
    // @see https://www.php.net/manual/en/language.types.type-juggling.php
    $typedValue = castSettingValue($rawValue, $settingType);

    // 💾 Store in the shared cache for subsequent calls within this request.
    configCacheStore('set', $key, $typedValue);

    return $typedValue;
}

// ==============================================================================
// ✏️ setConfig() - Update or insert a configuration setting
// ==============================================================================

/**
 * ✏️ Update or insert a configuration setting
 *
 * Updates an existing setting in tblSettings, or inserts a new one if the
 * key does not exist (upsert). If the setting is marked as isSensitive
 * (or $options['isSensitive'] is true for new settings), the value is
 * encrypted before storage.
 *
 * The static cache is invalidated for the affected key so the next call
 * to getConfig() will fetch the fresh value.
 *
 * @param string $key     The settingKey to update or insert
 * @param mixed  $value   The new value to store
 * @param array  $options Optional configuration for insert:
 *                        - 'group'       (string)  settingGroup (default: 'general')
 *                        - 'type'        (string)  settingType (default: 'string')
 *                        - 'isSensitive' (bool)    Whether to encrypt (default: false)
 *                        - 'isEditable'  (bool)    Whether editable via admin UI (default: true)
 *                        - 'description' (string)  Human-readable description (default: '')
 *
 * @return bool True on success, false on failure
 *
 * @see https://dev.mysql.com/doc/refman/8.0/en/insert-on-duplicate.html
 *
 * @example
 *   // Update an existing setting
 *   setConfig('site_name', 'My QR Service');
 *
 *   // Insert a new setting with options
 *   setConfig('new_feature_flag', '1', [
 *       'group'       => 'general',
 *       'type'        => 'boolean',
 *       'isSensitive' => false,
 *       'description' => 'Enable or disable new feature'
 *   ]);
 *
 *   // Store a sensitive API key (will be encrypted)
 *   setConfig('third_party_api_key', 'sk_live_abc123', [
 *       'group'       => 'api',
 *       'type'        => 'string',
 *       'isSensitive' => true,
 *       'description' => 'Third-party service API key'
 *   ]);
 */
function setConfig(string $key, mixed $value, array $options = []): bool
{
    // 📌 Extract options with defaults
    if (isset($options['group'])) {
        $group = $options['group'];
    } else {
        $group = 'general';
    }
    if (isset($options['type'])) {
        $type = $options['type'];
    } else {
        $type = 'string';
    }
    if (isset($options['isSensitive'])) {
        $isSensitive = $options['isSensitive'];
    } else {
        $isSensitive = false;
    }
    if (isset($options['isEditable'])) {
        $isEditable = $options['isEditable'];
    } else {
        $isEditable = true;
    }
    if (isset($options['description'])) {
        $description = $options['description'];
    } else {
        $description = '';
    }

    // 🔄 Convert value to string for storage
    // JSON values are encoded; booleans become '0'/'1'; all others cast to string.
    // @see https://www.php.net/manual/en/function.json-encode.php
    $storeValue = convertValueForStorage($value, $type);

    // 🔍 Check if the setting already exists to determine isSensitive flag
    // For existing settings, we respect the database isSensitive flag rather
    // than requiring the caller to specify it every time.
    try {
        $checkStmt = dbQuery(
            "SELECT `settingID`, `isSensitive` "
            . "FROM `tblSettings` "
            . "WHERE `settingKey` = ? "
            . "LIMIT 1",
            's',
            [$key]
        );

        $checkResult = $checkStmt->get_result();
        $existing    = $checkResult->fetch_assoc();
        $checkStmt->close();
    } catch (\RuntimeException $e) {
        if (function_exists('logError')) {
            logError('error', 'Config check failed', 'Key: ' . $key . ' | ' . $e->getMessage());
        }
        return false;
    }

    // 🔐 Determine the effective isSensitive flag
    // For existing settings, use the database value; for new settings, use the option.
    if ($existing !== null) {
        $effectiveSensitive = (int) $existing['isSensitive'];
    } else {
        if ($isSensitive) {
            $effectiveSensitive = 1;
        } else {
            $effectiveSensitive = 0;
        }
    }

    // 🔒 Encrypt the value if marked as sensitive
    // @see fn_encryption.php - encryptValue()
    if ($effectiveSensitive === 1 && $storeValue !== null && $storeValue !== '') {
        if (function_exists('encryptValue')) {
            try {
                $storeValue = encryptValue($storeValue);
            } catch (\RuntimeException $e) {
                if (function_exists('logError')) {
                    logError(
                        'error',
                        'Config encryption failed',
                        'Key: ' . $key . ' | ' . $e->getMessage()
                    );
                }
                return false;
            }
        } else {
            // ⚠️ Cannot encrypt without fn_encryption.php
            if (function_exists('logError')) {
                logError(
                    'warning',
                    'Cannot encrypt config value',
                    'Key: ' . $key . ' - fn_encryption.php not loaded'
                );
            }
            return false;
        }
    }

    // 🗄️ Upsert the setting using INSERT ... ON DUPLICATE KEY UPDATE
    // This handles both new settings and updates to existing ones in a single query.
    // @see https://dev.mysql.com/doc/refman/8.0/en/insert-on-duplicate.html
    try {
        if ($existing !== null) {
            // ✏️ Update existing setting
            $stmt = dbQuery(
                "UPDATE `tblSettings` "
                . "SET `settingValue` = ? "
                . "WHERE `settingKey` = ?",
                'ss',
                [$storeValue, $key]
            );
        } else {
            // ➕ Insert new setting
            if ($isEditable) {
                $isEditableInt = 1;
            } else {
                $isEditableInt = 0;
            }
            $stmt = dbQuery(
                "INSERT INTO `tblSettings` "
                . "(`settingGroup`, `settingKey`, `settingValue`, `settingType`, `isSensitive`, `isEditable`, `description`) "
                . "VALUES (?, ?, ?, ?, ?, ?, ?)",
                'ssssiis',
                [
                    $group,
                    $key,
                    $storeValue,
                    $type,
                    $effectiveSensitive,
                    $isEditableInt,
                    $description
                ]
            );
        }
        $stmt->close();
    } catch (\RuntimeException $e) {
        if (function_exists('logError')) {
            logError('error', 'Config save failed', 'Key: ' . $key . ' | ' . $e->getMessage());
        }
        return false;
    }

    // 🗑️ Invalidate the static cache for this key so getConfig() fetches fresh data
    // We call a helper to clear the specific cache entry.
    clearConfigCache($key);

    return true;
}

// ==============================================================================
// 📂 getConfigGroup() - Fetch all settings in a group
// ==============================================================================

/**
 * 📂 Fetch all configuration settings within a specific group
 *
 * Returns an associative array of settingKey => typedValue for all settings
 * in the specified settingGroup. Sensitive values are decrypted, and all
 * values are type-cast according to their settingType.
 *
 * Results are also cached individually in the static cache so that
 * subsequent getConfig() calls for these keys avoid database queries.
 *
 * @param string $group The settingGroup to retrieve (e.g., 'general', 'qr_defaults', 'security')
 *
 * @return array Associative array of settingKey => typedValue pairs
 *
 * @example
 *   $qrDefaults = getConfigGroup('qr_defaults');
 *   // ['default_qr_size' => 800, 'default_qr_ecc' => 'H', ...]
 *
 *   $securitySettings = getConfigGroup('security');
 *   // ['debug_mode' => false, 'session_lifetime' => 3600, ...]
 */
function getConfigGroup(string $group): array
{
    // 🗄️ Query all settings in the specified group
    // @see https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php
    try {
        $stmt = dbQuery(
            "SELECT `settingKey`, `settingValue`, `settingType`, `isSensitive` "
            . "FROM `tblSettings` "
            . "WHERE `settingGroup` = ? "
            . "ORDER BY `settingKey` ASC",
            's',
            [$group]
        );

        $result   = $stmt->get_result();
        $settings = [];

        // 🔄 Process each row: decrypt if needed, type-cast, and cache
        while ($row = $result->fetch_assoc()) {
            $key         = $row['settingKey'];
            $rawValue    = $row['settingValue'];
            $settingType = $row['settingType'];
            $isSensitive = (int) $row['isSensitive'];

            // 🔓 Decrypt sensitive values
            if ($isSensitive === 1 && $rawValue !== null && $rawValue !== '') {
                if (function_exists('decryptValue')) {
                    try {
                        $rawValue = decryptValue($rawValue);
                    } catch (\RuntimeException $e) {
                        if (function_exists('logError')) {
                            logError(
                                'error',
                                'Config group decryption failed',
                                'Group: ' . $group . ' | Key: ' . $key . ' | ' . $e->getMessage()
                            );
                        }
                        // ⚠️ Skip this value - use null
                        $rawValue = null;
                    }
                }
            }

            // 🔄 Type-cast the value
            $typedValue    = castSettingValue($rawValue, $settingType);
            $settings[$key] = $typedValue;
        }

        $stmt->close();
    } catch (\RuntimeException $e) {
        if (function_exists('logError')) {
            logError(
                'error',
                'Config group fetch failed',
                'Group: ' . $group . ' | ' . $e->getMessage()
            );
        }
        return [];
    }

    // 💾 Populate the static cache with each fetched setting
    // This way, subsequent getConfig('individual_key') calls are served from cache.
    foreach ($settings as $settingKey => $settingValue) {
        populateConfigCache($settingKey, $settingValue);
    }

    return $settings;
}

// ==============================================================================
// 📋 getAllConfig() - Fetch all settings (cached)
// ==============================================================================

/**
 * 📋 Fetch all configuration settings from the database
 *
 * Returns an associative array of settingKey => typedValue for every setting
 * in tblSettings. This is useful for admin panels or bulk operations.
 *
 * The entire result set is cached so subsequent calls within the same
 * request return instantly without a database query.
 *
 * @return array Associative array of all settingKey => typedValue pairs
 *
 * @example
 *   $allSettings = getAllConfig();
 *   // ['site_name' => 'CueRCode', 'debug_mode' => false, 'max_qr_size' => 4000, ...]
 */
function getAllConfig(): array
{
    // 📌 Static variable to cache the full result set
    // @see https://www.php.net/manual/en/language.variables.scope.php#language.variables.scope.static
    static $allCached = null;

    // 🔍 Return from cache if already fetched
    if ($allCached !== null) {
        return $allCached;
    }

    // 🗄️ Query all settings ordered by group then key
    try {
        $stmt = dbQuery(
            "SELECT `settingKey`, `settingValue`, `settingType`, `isSensitive` "
            . "FROM `tblSettings` "
            . "ORDER BY `settingGroup` ASC, `settingKey` ASC",
            '',
            []
        );

        $result   = $stmt->get_result();
        $settings = [];

        // 🔄 Process each row
        while ($row = $result->fetch_assoc()) {
            $key         = $row['settingKey'];
            $rawValue    = $row['settingValue'];
            $settingType = $row['settingType'];
            $isSensitive = (int) $row['isSensitive'];

            // 🔓 Decrypt sensitive values
            if ($isSensitive === 1 && $rawValue !== null && $rawValue !== '') {
                if (function_exists('decryptValue')) {
                    try {
                        $rawValue = decryptValue($rawValue);
                    } catch (\RuntimeException $e) {
                        if (function_exists('logError')) {
                            logError(
                                'error',
                                'Config decrypt failed (getAllConfig)',
                                'Key: ' . $key . ' | ' . $e->getMessage()
                            );
                        }
                        $rawValue = null;
                    }
                }
            }

            // 🔄 Type-cast the value
            $typedValue     = castSettingValue($rawValue, $settingType);
            $settings[$key] = $typedValue;
        }

        $stmt->close();
    } catch (\RuntimeException $e) {
        if (function_exists('logError')) {
            logError('error', 'getAllConfig failed', $e->getMessage());
        }
        return [];
    }

    // 💾 Cache the full result and populate per-key cache
    $allCached = $settings;

    foreach ($settings as $settingKey => $settingValue) {
        populateConfigCache($settingKey, $settingValue);
    }

    return $settings;
}

// ==============================================================================
// 🔄 castSettingValue() - Type-cast a raw setting value
// ==============================================================================

/**
 * 🔄 Cast a raw database value to its appropriate PHP type
 *
 * Converts the string value from tblSettings.settingValue to the correct
 * PHP type based on tblSettings.settingType.
 *
 * @param string|null $value The raw string value from the database
 * @param string      $type  The settingType ('string', 'integer', 'float', 'boolean', 'json', etc.)
 *
 * @return mixed The type-cast value
 *
 * @see https://www.php.net/manual/en/function.json-decode.php
 * @see https://www.php.net/manual/en/function.intval.php
 * @see https://www.php.net/manual/en/function.floatval.php
 * @see https://www.php.net/manual/en/function.filter-var.php
 */
function castSettingValue(?string $value, string $type): mixed
{
    // 📌 Handle null values - return appropriate typed null/default
    if ($value === null) {
        switch ($type) {
            case 'integer':
                return 0;
                break;
            case 'float':
                return 0.0;
                break;
            case 'boolean':
                return false;
                break;
            case 'json':
                return [];
                break;
            default:
                return null;
                break;
        }
    }

    // 🔄 Cast based on settingType
    // @see https://www.php.net/manual/en/language.types.type-juggling.php
    switch ($type) {

        // 🔢 Integer: cast to int
        // @see https://www.php.net/manual/en/function.intval.php
        case 'integer':
            return (int) $value;
            break;

        // 🔢 Float: cast to float
        // @see https://www.php.net/manual/en/function.floatval.php
        case 'float':
            return (float) $value;
            break;

        // ✅ Boolean: interpret common truthy/falsy values
        // '1', 'true', 'yes', 'on' => true; everything else => false
        // @see https://www.php.net/manual/en/function.filter-var.php
        case 'boolean':
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
            break;

        // 📋 JSON: decode to PHP array
        // Uses associative mode (true) so JSON objects become PHP arrays.
        // @see https://www.php.net/manual/en/function.json-decode.php
        case 'json':
            $jsonResult = json_decode($value, true);
            if ($jsonResult !== null) {
                return $jsonResult;
            } else {
                return [];
            }
            break;

        // 🔤 String, color, url, path, email: return as-is
        // These types are semantically different but stored as plain strings.
        default:
            return $value;
            break;
    }
}

// ==============================================================================
// 🔄 convertValueForStorage() - Convert a PHP value to string for database
// ==============================================================================

/**
 * 🔄 Convert a PHP value to a string suitable for database storage
 *
 * Performs the reverse of castSettingValue(): converts typed PHP values
 * back into string format for storage in tblSettings.settingValue.
 *
 * @param mixed  $value The PHP value to convert
 * @param string $type  The settingType for conversion context
 *
 * @return string|null The string representation for database storage
 *
 * @see https://www.php.net/manual/en/function.json-encode.php
 */
function convertValueForStorage(mixed $value, string $type): ?string
{
    // 📌 Handle null
    if ($value === null) {
        return null;
    }

    // 🔄 Convert based on settingType
    switch ($type) {

        // ✅ Boolean: store as '0' or '1'
        case 'boolean':
            if ($value) {
                return '1';
            } else {
                return '0';
            }
            break;

        // 📋 JSON: encode array/object to JSON string
        // @see https://www.php.net/manual/en/function.json-encode.php
        case 'json':
            if (is_string($value)) {
                return $value;
            } else {
                return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            break;

        // 🔢 Integer/Float: cast to string
        case 'integer':
        case 'float':
            return (string) $value;
            break;

        // 🔤 All other types: ensure string
        default:
            return (string) $value;
            break;
    }
}

// ==============================================================================
// 🗑️ clearConfigCache() - Invalidate cached config entries
// ==============================================================================

/**
 * 🗑️ Clear the static config cache for a specific key or all keys
 *
 * After modifying a setting via setConfig(), the cache for that key is
 * invalidated so subsequent getConfig() calls fetch fresh data.
 *
 * @param string|null $key The settingKey to clear, or null to clear all
 *
 * @return void
 *
 * @see https://www.php.net/manual/en/language.variables.scope.php#language.variables.scope.static
 */
function clearConfigCache(?string $key = null): void
{
    // 🐛 B-004 FIX: actually EVICT from the shared cache so the next getConfig()
    //    re-reads from the database. Previously this wrote a boolean into an
    //    unrelated static that getConfig() never consulted, so the eviction was
    //    a no-op and a stale value survived a setConfig() in the same request.
    // @see configCacheStore() - the single shared static store
    if ($key === null) {
        // 🗑️ Flush the entire cache — forces a full refresh on next read.
        configCacheStore('clearAll');
    } else {
        // 🗑️ Evict just the affected key.
        configCacheStore('clear', $key);
    }
}

// ==============================================================================
// 💾 populateConfigCache() - Add an entry to the config cache
// ==============================================================================

/**
 * 💾 Add a key-value pair to the config cache
 *
 * Used internally by getConfigGroup() and getAllConfig() to populate
 * the per-key cache so that subsequent getConfig() calls are served
 * from memory.
 *
 * @param string $key   The settingKey
 * @param mixed  $value The typed value
 *
 * @return void
 */
function populateConfigCache(string $key, mixed $value): void
{
    // 💾 B-004 FIX: warm the SHARED cache so a subsequent getConfig() for this
    //    key is served from memory. getConfigGroup()/getAllConfig() call this for
    //    every row they fetch, so a single bulk read primes the per-key cache.
    //    Previously this was a no-op and getConfig() re-queried the DB for each
    //    key despite the bulk fetch already having the value in hand.
    // @see configCacheStore() - the single shared static store
    // @see getConfigGroup(), getAllConfig() - callers that warm the cache
    configCacheStore('set', $key, $value);
}

// ==============================================================================
// 🗃️ configCacheStore() - Shared static config cache store (B-004)
// ==============================================================================

/**
 * 🗃️ Single shared static store backing the per-request config cache
 *
 * 🐛 B-004: getConfig() previously owned a PRIVATE static $cache that no other
 * function could see, so clearConfigCache() and populateConfigCache() could not
 * actually evict or warm it — they operated on unrelated statics. This function
 * centralises the cache in ONE static array that getConfig() (read + write),
 * setConfig() (via clearConfigCache eviction), getConfigGroup()/getAllConfig()
 * (via populateConfigCache warming) all share, so invalidation and warming take
 * real effect within a request.
 *
 * The store distinguishes a cached NULL value (a real setting whose typed value
 * is null) from "absent" by tracking presence with array_key_exists(), so a
 * legitimately-null cached value is still returned from cache rather than
 * triggering a re-query.
 *
 * @param string      $op    The operation: 'get' | 'set' | 'has' | 'clear' | 'clearAll'
 * @param string|null $key   The settingKey (required for get/set/has/clear)
 * @param mixed       $value The value to cache (only used by 'set')
 *
 * @return mixed For 'get' the cached value (or null if absent); for 'has' a bool;
 *               for set/clear/clearAll, null.
 *
 * @see https://www.php.net/manual/en/language.variables.scope.php#language.variables.scope.static
 * @see https://www.php.net/manual/en/function.array-key-exists.php
 */
function configCacheStore(string $op, ?string $key = null, mixed $value = null): mixed
{
    // 📌 The one and only request-lifetime config cache.
    static $store = [];

    switch ($op) {

        // 🔍 Presence test (distinguishes a cached null from "not cached").
        case 'has':
            return $key !== null && array_key_exists($key, $store);

        // 📖 Fetch a cached value (returns null if absent — callers gate on 'has').
        case 'get':
            if ($key !== null && array_key_exists($key, $store)) {
                return $store[$key];
            }
            return null;

        // 💾 Cache a value under a key.
        case 'set':
            if ($key !== null) {
                $store[$key] = $value;
            }
            return null;

        // 🗑️ Evict a single key.
        case 'clear':
            if ($key !== null && array_key_exists($key, $store)) {
                unset($store[$key]);
            }
            return null;

        // 🗑️ Flush the whole cache.
        case 'clearAll':
            $store = [];
            return null;

        // ⚠️ Unknown op — no-op (defensive).
        default:
            return null;
    }
}
